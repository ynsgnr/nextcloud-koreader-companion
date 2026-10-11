<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Article;

/**
 * Rebuilds untrusted article HTML from an allowlist of elements and attributes.
 *
 * Unknown elements are unwrapped (their content stays), dangerous ones are
 * removed with their content, and every attribute outside the allowlist goes.
 */
class HtmlSanitizer {

    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code',
        'em', 'strong', 'b', 'i', 'u', 's', 'sub', 'sup', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'figure', 'figcaption',
        'a', 'img', 'span', 'div', 'section', 'article',
    ];

    private const REMOVED_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select',
        'textarea', 'svg', 'math', 'noscript', 'link', 'meta', 'video', 'audio', 'canvas',
        'template', 'head', 'title',
    ];

    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href'],
        'img' => ['src', 'alt'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
    ];

    /** Lazy-loading pages keep a placeholder in src, so the real address comes first. */
    private const IMAGE_SOURCE_ATTRIBUTES = ['data-src', 'data-lazy-src', 'data-original', 'src'];

    private const WRAPPER_TAGS = ['div', 'span', 'section', 'article'];

    /** Strict XML parsers in e-readers stop at a depth of 256. */
    private const MAX_WRAPPER_DEPTH = 60;

    /** @return \DOMElement the sanitized <body> */
    public function sanitize(string $html, string $pageUrl): \DOMElement {
        $body = $this->parse($html)->getElementsByTagName('body')->item(0)
            ?? throw new \RuntimeException('Article has no content');

        $this->sanitizeChildren($body, $pageUrl);
        $this->unwrapDeepWrappers($body->ownerDocument);

        return $body;
    }

    private function parse(string $html): \DOMDocument {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previousErrorHandling = libxml_use_internal_errors(true);
        try {
            // Without the XML declaration libxml reads the bytes as Latin-1.
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorHandling);
        }

        return $document;
    }

    private function sanitizeChildren(\DOMNode $parent, string $pageUrl): void {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (!$node instanceof \DOMElement) {
                if ($node instanceof \DOMComment || $node instanceof \DOMProcessingInstruction) {
                    $parent->removeChild($node);
                }
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, self::REMOVED_TAGS, true)) {
                $parent->removeChild($node);
                continue;
            }

            $this->sanitizeChildren($node, $pageUrl);

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->unwrap($node);
                continue;
            }

            $this->sanitizeAttributes($node, $tag, $pageUrl);

            if ($tag === 'img' && !$node->hasAttribute('src')) {
                $parent->removeChild($node);
            } elseif ($tag === 'a' && !$node->hasAttribute('href')) {
                $this->unwrap($node);
            }
        }
    }

    private function sanitizeAttributes(\DOMElement $element, string $tag, string $pageUrl): void {
        if ($tag === 'img') {
            $this->useRealImageSource($element);
        }

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
            $url = $this->absoluteHttpUrl($element->getAttribute($name), $pageUrl);
            if ($url === null) {
                $element->removeAttribute($name);
            } else {
                $element->setAttribute($name, $url);
            }
        }
    }

    private function useRealImageSource(\DOMElement $image): void {
        foreach (self::IMAGE_SOURCE_ATTRIBUTES as $name) {
            $value = trim($image->getAttribute($name));
            if ($value !== '' && !str_starts_with(strtolower($value), 'data:')) {
                $image->setAttribute('src', $value);
                return;
            }
        }
        $image->removeAttribute('src');
    }

    /** Null for anything that is not an http(s) address, such as javascript:, data: or mailto:. */
    private function absoluteHttpUrl(string $url, string $pageUrl): ?string {
        $url = trim($url);
        $page = parse_url($pageUrl) ?: [];

        if (str_starts_with($url, '//')) {
            $url = ($page['scheme'] ?? 'https') . ':' . $url;
        }

        $parts = parse_url($url);
        if ($url === '' || $parts === false) {
            return null;
        }
        if (isset($parts['scheme'])) {
            return in_array(strtolower($parts['scheme']), ['http', 'https'], true) ? $url : null;
        }

        $pageIsHttp = in_array($page['scheme'] ?? '', ['http', 'https'], true) && isset($page['host']);
        if (!$pageIsHttp || str_starts_with($url, '#')) {
            return null;
        }

        $origin = $page['scheme'] . '://' . $page['host'] . (isset($page['port']) ? ':' . $page['port'] : '');
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        return $origin . preg_replace('#[^/]*$#', '', $page['path'] ?? '/') . $url;
    }

    private function unwrapDeepWrappers(\DOMDocument $document): void {
        $tests = implode(' or ', array_map(static fn (string $tag): string => "self::$tag", self::WRAPPER_TAGS));
        $deepWrappers = (new \DOMXPath($document))->query(
            sprintf('//body//*[(%s) and count(ancestor::*) > %d]', $tests, self::MAX_WRAPPER_DEPTH)
        );

        foreach (iterator_to_array($deepWrappers) as $wrapper) {
            $this->unwrap($wrapper);
        }
    }

    private function unwrap(\DOMElement $element): void {
        $parent = $element->parentNode;
        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }
}
