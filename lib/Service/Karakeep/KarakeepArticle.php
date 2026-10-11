<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Karakeep;

/** A link bookmark as Karakeep returned it. */
final readonly class KarakeepArticle {

    public function __construct(
        public string $id,
        public string $title,
        public string $author,
        public string $url,
        public ?string $html,
        public ?string $contentAssetId,
    ) {
    }
}
