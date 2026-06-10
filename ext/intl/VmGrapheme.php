<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * Grapheme cluster string helpers (php-src ext/intl/grapheme/grapheme_string.c; #7128, #7888).
 *
 * PHP UTF-8 grapheme split via `\X` — no host ext-intl delegation (pairs {@see JitGrapheme}).
 */
final class VmGrapheme
{
    public static function strContains(string $haystack, string $needle): bool
    {
        if ('' === $needle) {
            return true;
        }

        return self::strContainsUtf8($haystack, $needle);
    }

    private static function strContainsUtf8(string $haystack, string $needle): bool
    {
        $hay = self::splitGraphemes($haystack);
        if (null === $hay) {
            return false;
        }
        $need = self::splitGraphemes($needle);
        if (null === $need) {
            return false;
        }
        $hayLen = \count($hay);
        $needLen = \count($need);
        if (0 === $needLen) {
            return true;
        }
        for ($i = 0; $i <= $hayLen - $needLen; ++$i) {
            $matched = true;
            for ($j = 0; $j < $needLen; ++$j) {
                if ($hay[$i + $j] !== $need[$j]) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>|null
     */
    private static function splitGraphemes(string $string): ?array
    {
        if ('' === $string) {
            return [];
        }
        if (!\preg_match_all('/\X/u', $string, $matches)) {
            return null;
        }

        return $matches[0];
    }
}
