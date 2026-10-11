<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Article;

use Masterminds\HTML5;

/** Turns one article's readable HTML into an EPUB. */
class HtmlToEpubService {

    private const DEFAULT_TITLE = 'Untitled article';
    private const MAX_IMAGE_DOWNLOADS = 30;

    private const WRAPPER_TAGS = ['div', 'span', 'section', 'article'];

    /** Strict XML parsers in e-readers stop at a depth of 256. */
    private const MAX_WRAPPER_DEPTH = 60;

    public function __construct(
        private ArticleSanitizer $sanitizer,
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

        $body = $this->parseBody($this->sanitizer->sanitize($html, $pageUrl));
        if (trim($body->textContent) === '') {
            throw new \RuntimeException('Article has no readable text');
        }

        $this->removeFirstHeadingRepeating($body, $title);
        $this->unwrapDeepWrappers($body);
        $images = $this->embedImages($body);
        $chapterXhtml = XmlText::withoutInvalidCharacters($this->innerXml($body));

        return $this->epubWriter->write($title, $author, $pageUrl, $identifier, $chapterXhtml, $images);
    }

    private function parseBody(string $html): \DOMElement {
        $document = (new HTML5(['disable_html_ns' => true]))->loadHTML("<!DOCTYPE html><html><body>$html</body></html>");

        return $document->getElementsByTagName('body')->item(0) ?? throw new \RuntimeException('Article has no content');
    }

    /** The EPUB adds the title as its own heading. */
    private function removeFirstHeadingRepeating(\DOMElement $body, string $title): void {
        $heading = $body->getElementsByTagName('h1')->item(0);
        if ($heading !== null && $this->normalize($heading->textContent) === $this->normalize($title)) {
            $heading->parentNode->removeChild($heading);
        }
    }

    private function unwrapDeepWrappers(\DOMElement $body): void {
        $isWrapper = implode(' or ', array_map(static fn (string $tag): string => "self::$tag", self::WRAPPER_TAGS));
        $query = sprintf('//*[(%s) and count(ancestor::*) > %d]', $isWrapper, self::MAX_WRAPPER_DEPTH);

        foreach (iterator_to_array((new \DOMXPath($body->ownerDocument))->query($query)) as $wrapper) {
            while ($wrapper->firstChild !== null) {
                $wrapper->parentNode->insertBefore($wrapper->firstChild, $wrapper);
            }
            $wrapper->parentNode->removeChild($wrapper);
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
