<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Article;

final readonly class EmbeddedImage {

    public function __construct(
        public string $bytes,
        public string $mimeType,
    ) {
    }

    public function fileExtension(): string {
        return $this->mimeType === 'image/png' ? 'png' : 'jpg';
    }
}
