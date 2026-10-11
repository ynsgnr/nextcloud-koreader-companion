<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Karakeep;

use OCA\KoreaderCompanion\AppInfo\Application;
use OCA\KoreaderCompanion\Service\Article\HtmlToEpubService;
use OCA\KoreaderCompanion\Service\FilenameService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Config\IUserConfig;
use Psr\Log\LoggerInterface;

/**
 * Saves the articles of a user's Karakeep list as EPUBs in their book folder.
 * The normal file listener indexes them, so they show up in OPDS like uploads.
 */
class KarakeepSyncService {

    private const MAX_IMPORTS_PER_RUN = 10;

    public function __construct(
        private KarakeepClient $client,
        private KarakeepSettings $settings,
        private HtmlToEpubService $htmlToEpub,
        private FilenameService $filenames,
        private IRootFolder $rootFolder,
        private IUserConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    public function sync(string $userId, KarakeepConnection $connection): KarakeepSyncResult {
        $state = $this->settings->importState($userId);
        $booksFolder = $this->booksFolder($userId);
        $imported = $notCrawledYet = $failed = 0;

        try {
            foreach ($this->client->articles($connection) as $article) {
                if ($imported >= self::MAX_IMPORTS_PER_RUN) {
                    break;
                }
                if ($state->isHandled($article->id)) {
                    continue;
                }

                try {
                    if ($this->import($connection, $article, $booksFolder)) {
                        $state->markHandled($article->id);
                        $imported++;
                    } else {
                        $notCrawledYet++;
                    }
                } catch (\Throwable $e) {
                    $state->recordFailure($article->id);
                    $failed++;
                    $this->logger->warning('Karakeep import failed', [
                        'app' => Application::APP_ID,
                        'bookmarkId' => $article->id,
                        'exception' => $e,
                    ]);
                }
            }
        } finally {
            $this->settings->saveImportState($userId, $state);
        }

        return new KarakeepSyncResult($imported, $notCrawledYet, $failed);
    }

    /** @return bool false when Karakeep has not crawled the page yet */
    private function import(KarakeepConnection $connection, KarakeepArticle $article, Folder $booksFolder): bool {
        $html = $article->html;
        if (($html === null || $html === '') && $article->contentAssetId !== null) {
            $html = $this->client->assetContent($connection, $article->contentAssetId);
        }
        if ($html === null || trim($html) === '') {
            return false;
        }

        $fileName = $this->filenames->sanitizeFilename($article->title !== '' ? $article->title : 'article')
            . " [karakeep-{$article->id}].epub";
        if (!$booksFolder->nodeExists($fileName)) {
            $epub = $this->htmlToEpub->convert($html, $article->title, $article->author, $article->url, 'urn:karakeep:' . $article->id);
            $booksFolder->newFile($fileName, $epub);
        }

        return true;
    }

    private function booksFolder(string $userId): Folder {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $name = $this->config->getValueString($userId, Application::APP_ID, 'folder', 'eBooks');
        try {
            $folder = $userFolder->get($name);
        } catch (NotFoundException) {
            $folder = $userFolder->newFolder($name);
        }

        return $folder instanceof Folder ? $folder : throw new \RuntimeException('The book folder is a file');
    }
}
