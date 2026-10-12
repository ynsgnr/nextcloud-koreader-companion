<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Article;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Makes untrusted article HTML safe for an EPUB.
 *
 * Elements outside the lists below are removed with their content.
 */
class ArticleSanitizer {

    private const MAX_INPUT_BYTES = 50 * 1024 * 1024;

    private const KEPT_ELEMENTS = [
        'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code',
        'em', 'strong', 'b', 'i', 'u', 's', 'sub', 'sup', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'caption', 'figure', 'figcaption',
        'span', 'div', 'section', 'article',
    ];

    private const KEPT_ELEMENTS_WITH_ATTRIBUTES = [
        'a' => ['href'],
        'img' => ['src', 'alt'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
    ];

    /** Removed themselves, but their content is kept. */
    private const UNWRAPPED_ELEMENTS = [
        'header', 'footer', 'main', 'aside', 'nav', 'center', 'font', 'details', 'summary',
        'picture', 'form', 'fieldset', 'label', 'abbr', 'cite', 'mark', 'small', 'time',
    ];

    public function sanitize(string $html, string $pageUrl): string {
        $config = new HtmlSanitizerConfig();
        foreach (self::KEPT_ELEMENTS as $element) {
            $config = $config->allowElement($element);
        }
        foreach (self::KEPT_ELEMENTS_WITH_ATTRIBUTES as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }
        foreach (self::UNWRAPPED_ELEMENTS as $element) {
            $config = $config->blockElement($element);
        }

        $config = $config
            ->allowAttribute('id', '*')
            ->allowLinkSchemes(['http', 'https'])
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->withMaxInputLength(self::MAX_INPUT_BYTES)
            ->withAttributeSanitizer(new PageUrlResolver($pageUrl));

        return (new HtmlSanitizer($config))->sanitize($html);
    }
}
