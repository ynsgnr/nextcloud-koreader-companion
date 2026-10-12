<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Article;

final class XmlText {

    /** Drops the characters XML 1.0 forbids, such as most control characters. */
    public static function withoutInvalidCharacters(string $text): string {
        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
    }

    public static function escape(string $text): string {
        return htmlspecialchars(self::withoutInvalidCharacters($text), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
