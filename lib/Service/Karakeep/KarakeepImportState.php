<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Karakeep;

/** Which bookmarks a user's import is finished with: imported, or failed too often to retry. */
final class KarakeepImportState {

    private const MAX_ATTEMPTS = 3;
    private const MAX_REMEMBERED = 2000;

    /**
     * @param string[] $handledIds
     * @param array<string, int> $failureCounts
     */
    private function __construct(
        private array $handledIds,
        private array $failureCounts,
    ) {
    }

    public static function fromJson(string $json): self {
        $data = json_decode($json, true);

        return new self(
            is_array($data['handled'] ?? null) ? array_values($data['handled']) : [],
            is_array($data['failures'] ?? null) ? $data['failures'] : [],
        );
    }

    public function toJson(): string {
        return json_encode([
            'handled' => array_slice($this->handledIds, -self::MAX_REMEMBERED),
            'failures' => $this->failureCounts,
        ], JSON_THROW_ON_ERROR);
    }

    public function isHandled(string $bookmarkId): bool {
        return in_array($bookmarkId, $this->handledIds, true);
    }

    public function markHandled(string $bookmarkId): void {
        $this->handledIds[] = $bookmarkId;
        unset($this->failureCounts[$bookmarkId]);
    }

    /** After too many failures the bookmark counts as handled and is not retried. */
    public function recordFailure(string $bookmarkId): void {
        $failures = ($this->failureCounts[$bookmarkId] ?? 0) + 1;
        if ($failures >= self::MAX_ATTEMPTS) {
            $this->markHandled($bookmarkId);
        } else {
            $this->failureCounts[$bookmarkId] = $failures;
        }
    }
}
