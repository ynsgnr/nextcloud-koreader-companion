<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Turns one article's readable HTML into a single-chapter EPUB 3.
 *
 * Built only on ext-dom, ext-zip and Nextcloud's own HTTP client, so the app
 * gains no composer dependency. The caller runs the result through
 * EpubOptimizerService; this class only decides what goes into the book.
 *
 * The input is untrusted web content. It is rebuilt from an element and
 * attribute allowlist rather than cleaned by a blocklist, so scripts, styles,
 * event handlers and unknown markup never reach the EPUB.
 *
 * Remote images go through IClientService, which refuses local and private
 * addresses unless the admin has allowed them, so a hostile article cannot
 * point the server at its own network.
 */
class HtmlToEpubService {

    private const MAX_IMAGES = 30;
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    private const IMAGE_TIMEOUT_SECONDS = 10;

    /** Tags kept as-is. Anything else is unwrapped (children kept) or, for DROP_TAGS, removed with its content. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code',
        'em', 'strong', 'b', 'i', 'u', 's', 'sub', 'sup', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'figure', 'figcaption',
        'a', 'img', 'span', 'div', 'section', 'article',
    ];

    private const DROP_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select',
        'textarea', 'svg', 'math', 'noscript', 'link', 'meta', 'video', 'audio', 'canvas',
        'template', 'head', 'title',
    ];

    /** @var array<string, string[]> */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href'],
        'img' => ['src', 'alt'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
    ];

    private const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $html Readable article HTML (a fragment or a full document)
     * @param string $baseUrl Page URL, used to resolve relative links and image sources
     * @return string The EPUB file contents
     * @throws \RuntimeException when the article has no usable text or the EPUB cannot be written
     */
    public function convert(string $html, string $title, string $author, string $baseUrl, string $identifier): string {
        $doc = $this->parse($html);
        $this->sanitize($doc, $baseUrl);

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null || trim($body->textContent) === '') {
            throw new \RuntimeException('Article has no readable text');
        }

        $images = $this->embedImages($doc);

        $chapter = '';
        foreach (iterator_to_array($body->childNodes) as $node) {
            $chapter .= $doc->saveXML($node);
        }

        $title = $title !== '' ? $title : 'Untitled article';

        return $this->package($title, $author, $baseUrl, $identifier, $chapter, $images);
    }

    private function parse(string $html): \DOMDocument {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            // The XML declaration makes libxml read the bytes as UTF-8 instead of Latin-1.
            $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $doc->encoding = 'UTF-8';

        return $doc;
    }

    private function sanitize(\DOMDocument $doc, string $baseUrl): void {
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return;
        }
        $this->sanitizeChildren($body, $baseUrl);
    }

    private function sanitizeChildren(\DOMNode $parent, string $baseUrl): void {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $parent->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_TAGS, true)) {
                $parent->removeChild($child);
                continue;
            }

            $this->sanitizeChildren($child, $baseUrl);

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                // Unknown wrapper: keep what is inside it.
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }

            $this->sanitizeAttributes($child, $tag, $baseUrl);

            if ($tag === 'img' && !$child->hasAttribute('src')) {
                $parent->removeChild($child);
            } elseif ($tag === 'a' && !$child->hasAttribute('href')) {
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
            }
        }
    }

    private function sanitizeAttributes(\DOMElement $element, string $tag, string $baseUrl): void {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];
        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (!in_array(strtolower($attribute->name), $allowed, true)) {
                $element->removeAttribute($attribute->name);
            }
        }

        foreach (['href', 'src'] as $name) {
            if (!$element->hasAttribute($name)) {
                continue;
            }
            $absolute = $this->resolveUrl(trim($element->getAttribute($name)), $baseUrl);
            if ($absolute === null) {
                $element->removeAttribute($name);
            } else {
                $element->setAttribute($name, $absolute);
            }
        }
    }

    /** Only http(s) survives; javascript:, data: and friends are dropped. */
    private function resolveUrl(string $url, string $baseUrl): ?string {
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        if (isset($parts['scheme'])) {
            return in_array(strtolower($parts['scheme']), ['http', 'https'], true) ? $url : null;
        }

        $base = parse_url($baseUrl);
        if ($base === false || !isset($base['scheme'], $base['host']) || !in_array(strtolower($base['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }
        if (str_starts_with($url, '#')) {
            return null;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $base['path'] ?? '/');

        return $origin . $dir . $url;
    }

    /**
     * Download each <img>, point it at a local file name, and return name => bytes.
     * An image that cannot be fetched is removed rather than left as a dead link.
     *
     * @return array<string, array{bytes: string, type: string}>
     */
    private function embedImages(\DOMDocument $doc): array {
        $client = $this->clientService->newClient();
        $images = [];
        $bySource = [];

        foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
            $src = $img->getAttribute('src');
            if (!isset($bySource[$src])) {
                $bySource[$src] = count($images) < self::MAX_IMAGES ? $this->fetchImage($client, $src) : null;
                if ($bySource[$src] !== null) {
                    $name = sprintf('img%03d.%s', count($images) + 1, self::IMAGE_TYPES[$bySource[$src]['type']]);
                    $images[$name] = $bySource[$src];
                    $bySource[$src]['name'] = $name;
                }
            }

            if ($bySource[$src] === null) {
                $img->parentNode?->removeChild($img);
                continue;
            }
            $img->setAttribute('src', 'images/' . $bySource[$src]['name']);
            if (!$img->hasAttribute('alt')) {
                $img->setAttribute('alt', '');
            }
        }

        return $images;
    }

    /** @return array{bytes: string, type: string}|null */
    private function fetchImage(\OCP\Http\Client\IClient $client, string $url): ?array {
        try {
            $response = $client->get($url, [
                'timeout' => self::IMAGE_TIMEOUT_SECONDS,
                'headers' => ['Accept' => 'image/jpeg,image/png'],
            ]);
            $bytes = $response->getBody();
            if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
                return null;
            }
            // Trust the bytes, not the Content-Type header.
            $info = @getimagesizefromstring($bytes);
            $type = $info['mime'] ?? '';

            return isset(self::IMAGE_TYPES[$type]) ? ['bytes' => $bytes, 'type' => $type] : null;
        } catch (\Throwable $e) {
            $this->logger->debug('Skipping an article image that could not be fetched', [
                'app' => 'koreader_companion',
                'url' => $url,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /** @param array<string, array{bytes: string, type: string}> $images */
    private function package(string $title, string $author, string $sourceUrl, string $identifier, string $chapter, array $images): string {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $path = tempnam(sys_get_temp_dir(), 'html_epub_');
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the EPUB archive');
        }

        try {
            // OCF wants `mimetype` first and stored, so readers can sniff it.
            $zip->addFromString('mimetype', 'application/epub+zip');
            $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);

            $zip->addFromString('META-INF/container.xml',
                '<?xml version="1.0" encoding="UTF-8"?>'
                . '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
                . '<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>'
                . '</container>');

            $manifest = '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
                . '<item id="chapter" href="chapter.xhtml" media-type="application/xhtml+xml"/>';
            foreach ($images as $name => $image) {
                $manifest .= '<item id="' . $esc(pathinfo($name, PATHINFO_FILENAME)) . '" href="images/' . $esc($name)
                    . '" media-type="' . $esc($image['type']) . '"/>';
                $zip->addFromString('OEBPS/images/' . $name, $image['bytes']);
            }

            $zip->addFromString('OEBPS/content.opf',
                '<?xml version="1.0" encoding="UTF-8"?>'
                . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">'
                . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
                . '<dc:identifier id="uid">' . $esc($identifier) . '</dc:identifier>'
                . '<dc:title>' . $esc($title) . '</dc:title>'
                . ($author !== '' ? '<dc:creator>' . $esc($author) . '</dc:creator>' : '')
                . '<dc:language>en</dc:language>'
                . ($sourceUrl !== '' ? '<dc:source>' . $esc($sourceUrl) . '</dc:source>' : '')
                . '<meta property="dcterms:modified">' . gmdate('Y-m-d\TH:i:s\Z') . '</meta>'
                . '</metadata>'
                . '<manifest>' . $manifest . '</manifest>'
                . '<spine><itemref idref="chapter"/></spine>'
                . '</package>');

            $zip->addFromString('OEBPS/nav.xhtml',
                '<?xml version="1.0" encoding="UTF-8"?>'
                . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><title>'
                . $esc($title) . '</title></head><body><nav epub:type="toc"><ol><li><a href="chapter.xhtml">'
                . $esc($title) . '</a></li></ol></nav></body></html>');

            $zip->addFromString('OEBPS/chapter.xhtml',
                '<?xml version="1.0" encoding="UTF-8"?>'
                . '<html xmlns="http://www.w3.org/1999/xhtml"><head><meta charset="utf-8"/><title>'
                . $esc($title) . '</title></head><body><h1>' . $esc($title) . '</h1>' . $chapter . '</body></html>');

            if (!$zip->close()) {
                throw new \RuntimeException('Could not write the EPUB archive');
            }
            $bytes = file_get_contents($path);

            return $bytes === false ? throw new \RuntimeException('Could not read the EPUB back') : $bytes;
        } finally {
            @unlink($path);
        }
    }
}
