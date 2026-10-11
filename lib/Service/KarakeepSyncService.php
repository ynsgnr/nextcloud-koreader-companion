<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service;

use OCP\Config\IUserConfig;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Pulls articles from a Karakeep list and stores each one as an EPUB in the
 * user's book folder, where the normal upload listener indexes it.
 *
 * Polling rather than a webhook: the webhook payload carries no article
 * content, so a callback would have to call the Karakeep API anyway.
 */
class KarakeepSyncService {

    private const APP_ID = 'koreader_companion';
    private const PAGE_SIZE = 20;
    private const MAX_PAGES = 3;
    private const MAX_IMPORTS_PER_RUN = 10;
    private const MAX_ATTEMPTS = 3;
    private const MAX_REMEMBERED_IDS = 2000;
    private const HTTP_TIMEOUT_SECONDS = 20;

    public function __construct(
        private IUserConfig $config,
        private IRootFolder $rootFolder,
        private IClientService $clientService,
        private HtmlToEpubService $htmlToEpub,
        private EpubOptimizerService $optimizer,
        private FilenameService $filenames,
        private LoggerInterface $logger,
    ) {
    }

    public function isConfigured(string $userId): bool {
        return $this->get($userId, 'karakeep_enabled', 'no') === 'yes'
            && $this->get($userId, 'karakeep_url') !== ''
            && $this->get($userId, 'karakeep_api_key') !== ''
            && $this->get($userId, 'karakeep_list_id') !== '';
    }

    /**
     * @return array{imported: int, skipped: int, failed: int}
     */
    public function sync(string $userId): array {
        $result = ['imported' => 0, 'skipped' => 0, 'failed' => 0];
        if (!$this->isConfigured($userId)) {
            return $result;
        }

        $state = $this->loadState($userId);
        $booksFolder = $this->booksFolder($userId);
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->listBookmarks($userId, $cursor);
            foreach ($data['bookmarks'] as $bookmark) {
                if ($result['imported'] >= self::MAX_IMPORTS_PER_RUN) {
                    break 2;
                }
                $id = (string)($bookmark['id'] ?? '');
                if ($id === '' || in_array($id, $state['done'], true)) {
                    continue;
                }
                if (($bookmark['content']['type'] ?? '') !== 'link') {
                    $this->markDone($state, $id);
                    $result['skipped']++;
                    continue;
                }

                try {
                    if ($this->importBookmark($userId, $booksFolder, $bookmark)) {
                        $this->markDone($state, $id);
                        $result['imported']++;
                    } else {
                        $result['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $state['fails'][$id] = ($state['fails'][$id] ?? 0) + 1;
                    if ($state['fails'][$id] >= self::MAX_ATTEMPTS) {
                        $this->markDone($state, $id);
                    }
                    $result['failed']++;
                    $this->logger->warning('Karakeep import failed', [
                        'app' => self::APP_ID,
                        'bookmarkId' => $id,
                        'exception' => $e,
                    ]);
                }
            }

            $cursor = $data['nextCursor'];
            if ($cursor === null) {
                break;
            }
        }

        $this->saveState($userId, $state);

        return $result;
    }

    /**
     * @return bool false when the article is not ready yet (not crawled), so it is retried next run
     */
    private function importBookmark(string $userId, Folder $booksFolder, array $bookmark): bool {
        $link = $bookmark['content'];
        $html = (string)($link['htmlContent'] ?? '');
        if ($html === '' && !empty($link['contentAssetId'])) {
            $html = $this->request($userId, '/api/v1/assets/' . rawurlencode((string)$link['contentAssetId']));
        }
        if (trim($html) === '') {
            return false;
        }

        $url = (string)($link['url'] ?? '');
        $title = trim((string)($bookmark['title'] ?? $link['title'] ?? ''));
        $id = (string)$bookmark['id'];

        $epub = $this->htmlToEpub->convert($html, $title, trim((string)($link['author'] ?? '')), $url, 'urn:karakeep:' . $id);
        $epub = $this->optimize($userId, $epub);

        $name = $this->filenames->sanitizeFilename($title !== '' ? $title : 'article') . ' [karakeep-' . $id . '].epub';
        $name = $this->filenames->resolveFilenameConflict($booksFolder, $name);
        $booksFolder->newFile($name, $epub);

        return true;
    }

    private function optimize(string $userId, string $epub): string {
        if ($this->get($userId, 'opds_optimize_enabled', 'yes') !== 'yes') {
            return $epub;
        }

        try {
            return $this->optimizer->optimize($epub, [
                'max_width' => (int)$this->get($userId, 'opds_optimize_max_width', '1600'),
                'max_height' => (int)$this->get($userId, 'opds_optimize_max_height', '2400'),
                'grayscale' => $this->get($userId, 'opds_optimize_grayscale', 'no') === 'yes',
                'quality' => 85,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Optimizing a Karakeep article failed; storing it unoptimized', [
                'app' => self::APP_ID,
                'exception' => $e,
            ]);

            return $epub;
        }
    }

    /**
     * @return array{bookmarks: array<int, array>, nextCursor: ?string}
     */
    private function listBookmarks(string $userId, ?string $cursor): array {
        $query = ['includeContent' => 'true', 'limit' => self::PAGE_SIZE];
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }
        $body = $this->request(
            $userId,
            '/api/v1/lists/' . rawurlencode($this->get($userId, 'karakeep_list_id')) . '/bookmarks?' . http_build_query($query)
        );
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['bookmarks']) || !is_array($data['bookmarks'])) {
            throw new \RuntimeException('Unexpected response from Karakeep');
        }

        return ['bookmarks' => $data['bookmarks'], 'nextCursor' => $data['nextCursor'] ?? null];
    }

    private function request(string $userId, string $path): string {
        $base = rtrim($this->get($userId, 'karakeep_url'), '/');
        $response = $this->clientService->newClient()->get($base . $path, [
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
            'headers' => ['Authorization' => 'Bearer ' . $this->get($userId, 'karakeep_api_key')],
        ]);

        return (string)$response->getBody();
    }

    private function booksFolder(string $userId): Folder {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $name = $this->get($userId, 'folder', 'eBooks');
        try {
            $folder = $userFolder->get($name);
        } catch (NotFoundException) {
            $folder = $userFolder->newFolder($name);
        }
        if (!$folder instanceof Folder) {
            throw new \RuntimeException('The configured book folder is a file');
        }

        return $folder;
    }

    /** @return array{done: string[], fails: array<string, int>} */
    private function loadState(string $userId): array {
        $state = json_decode($this->get($userId, 'karakeep_state', '{}'), true);

        return [
            'done' => is_array($state['done'] ?? null) ? array_values($state['done']) : [],
            'fails' => is_array($state['fails'] ?? null) ? $state['fails'] : [],
        ];
    }

    private function saveState(string $userId, array $state): void {
        $state['done'] = array_slice($state['done'], -self::MAX_REMEMBERED_IDS);
        $this->config->setValueString($userId, self::APP_ID, 'karakeep_state', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function markDone(array &$state, string $id): void {
        $state['done'][] = $id;
        unset($state['fails'][$id]);
    }

    private function get(string $userId, string $key, string $default = ''): string {
        return $this->config->getValueString($userId, self::APP_ID, $key, $default);
    }
}
