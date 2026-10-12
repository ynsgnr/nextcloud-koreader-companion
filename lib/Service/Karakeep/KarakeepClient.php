<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Karakeep;

use OCP\Http\Client\IClientService;

/** The two Karakeep API calls the import needs. */
class KarakeepClient {

    private const PAGE_SIZE = 20;
    private const MAX_PAGES = 3;
    private const TIMEOUT_SECONDS = 20;

    public function __construct(
        private IClientService $clientService,
    ) {
    }

    /**
     * The newest link bookmarks of the connection's list; pages are fetched as the caller asks for more.
     *
     * @return \Generator<int, KarakeepArticle>
     */
    public function articles(KarakeepConnection $connection): \Generator {
        $cursor = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['includeContent' => 'true', 'limit' => self::PAGE_SIZE] + ($cursor === null ? [] : ['cursor' => $cursor]);
            $response = json_decode(
                $this->get($connection, '/api/v1/lists/' . rawurlencode($connection->listId) . '/bookmarks?' . http_build_query($query)),
                true
            );
            if (!is_array($response['bookmarks'] ?? null)) {
                throw new \RuntimeException('Unexpected response from Karakeep');
            }

            foreach ($response['bookmarks'] as $bookmark) {
                if (($bookmark['content']['type'] ?? '') === 'link') {
                    yield $this->toArticle($bookmark);
                }
            }

            $cursor = $response['nextCursor'] ?? null;
            if ($cursor === null) {
                return;
            }
        }
    }

    /** Karakeep stores long articles as an asset instead of inline. */
    public function assetContent(KarakeepConnection $connection, string $assetId): string {
        return $this->get($connection, '/api/v1/assets/' . rawurlencode($assetId));
    }

    private function toArticle(array $bookmark): KarakeepArticle {
        $link = $bookmark['content'];

        return new KarakeepArticle(
            (string)$bookmark['id'],
            trim((string)($bookmark['title'] ?? $link['title'] ?? '')),
            trim((string)($link['author'] ?? '')),
            (string)($link['url'] ?? ''),
            $link['htmlContent'] ?? null,
            $link['contentAssetId'] ?? null,
        );
    }

    private function get(KarakeepConnection $connection, string $path): string {
        return (string)$this->clientService->newClient()->get($connection->url . $path, [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => ['Authorization' => 'Bearer ' . $connection->apiKey],
        ])->getBody();
    }
}
