<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Karakeep;

final readonly class KarakeepSyncResult implements \JsonSerializable {

    public function __construct(
        public int $imported,
        public int $notCrawledYet,
        public int $failed,
    ) {
    }

    #[\Override]
    public function jsonSerialize(): array {
        return [
            'imported' => $this->imported,
            'not_crawled_yet' => $this->notCrawledYet,
            'failed' => $this->failed,
        ];
    }
}
