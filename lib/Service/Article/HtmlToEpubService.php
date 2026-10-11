<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Article;

/** Turns one article's readable HTML into an EPUB. */
class HtmlToEpubService {

    private const DEFAULT_TITLE = 'Untitled article';
    private const MAX_IMAGE_DOWNLOADS = 30;

    public function __construct(
        private HtmlSanitizer $sanitizer,
        private ImageDownloader $imageDownloader,
        private EpubWriter $epubWriter,
    ) {
    }

    /**
     * @param string $pageUrl Where the article came from; relative links and image sources resolve against it
     * @throws \RuntimeException when the article has no readable text
     */
    public function convert(string $html, string $title, string $author, string $pageUrl, string $identifier): string {
        $title = $title !== '' ? $title : self::DEFAULT_TITLE;

        $body = $this->sanitizer->sanitize($html, $pageUrl);
        if (trim($body->textContent) === '') {
            throw new \RuntimeException('Article has no readable text');
        }

        $this->removeFirstHeadingRepeating($body, $title);
        $images = $this->embedImages($body);

        return $this->epubWriter->write($title, $author, $pageUrl, $identifier, $this->innerXml($body), $images);
    }

    /** The EPUB adds the title as its own heading. */
    private function removeFirstHeadingRepeating(\DOMElement $body, string $title): void {
        $heading = $body->getElementsByTagName('h1')->item(0);
        if ($heading !== null && $this->normalize($heading->textContent) === $this->normalize($title)) {
            $heading->parentNode->removeChild($heading);
        }
    }

    /**
     * Downloads each image, points the <img> at its file inside the EPUB, and removes the ones that failed.
     *
     * @return array<string, EmbeddedImage> images by file name
     */
    private function embedImages(\DOMElement $body): array {
        $files = [];
        $fileNameBySource = [];

        foreach (iterator_to_array($body->getElementsByTagName('img')) as $image) {
            $source = $image->getAttribute('src');
            if (!array_key_exists($source, $fileNameBySource)) {
                $fileNameBySource[$source] = count($fileNameBySource) < self::MAX_IMAGE_DOWNLOADS
                    ? $this->storeImage($files, $source)
                    : null;
            }

            $fileName = $fileNameBySource[$source];
            if ($fileName === null) {
                $image->parentNode->removeChild($image);
                continue;
            }
            $image->setAttribute('src', 'images/' . $fileName);
            if (!$image->hasAttribute('alt')) {
                $image->setAttribute('alt', '');
            }
        }

        return $files;
    }

    /**
     * @param array<string, EmbeddedImage> $files
     * @return string|null the new file's name, or null when the image could not be downloaded
     */
    private function storeImage(array &$files, string $source): ?string {
        $image = $this->imageDownloader->download($source);
        if ($image === null) {
            return null;
        }

        $fileName = sprintf('img%03d.%s', count($files) + 1, $image->fileExtension());
        $files[$fileName] = $image;

        return $fileName;
    }

    private function innerXml(\DOMElement $element): string {
        $xml = '';
        foreach ($element->childNodes as $child) {
            $xml .= $element->ownerDocument->saveXML($child);
        }

        return $xml;
    }

    private function normalize(string $text): string {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
