<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Karakeep;

final readonly class KarakeepConnection {

    public function __construct(
        public string $url,
        public string $apiKey,
        public string $listId,
    ) {
    }
}
