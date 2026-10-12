<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Article;

use League\Uri\Uri;
use League\Uri\UriResolver;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/** Turns the relative links and image sources of an article into absolute URLs. */
final class PageUrlResolver implements AttributeSanitizerInterface {

    private ?Uri $pageUri;

    public function __construct(string $pageUrl) {
        try {
            $this->pageUri = Uri::new($pageUrl);
        } catch (\Throwable) {
            $this->pageUri = null;
        }
    }

    #[\Override]
    public function getSupportedElements(): ?array {
        return ['a', 'img'];
    }

    #[\Override]
    public function getSupportedAttributes(): ?array {
        return ['href', 'src'];
    }

    #[\Override]
    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string {
        if (str_starts_with($value, '#')) {
            return $value;
        }
        if ($this->pageUri === null) {
            return null;
        }

        try {
            $url = UriResolver::resolve(Uri::new($value), $this->pageUri);
        } catch (\Throwable) {
            return null;
        }

        return $url->getScheme() === null ? null : $url->toString();
    }
}
