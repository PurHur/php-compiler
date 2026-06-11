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
     * grapheme_str_split() — split into grapheme clusters (php-src ext/intl/grapheme; #5958).
     *
     * @return list<string>|false
     */
    public static function strSplit(string $string, int $length = 1): array|false
    {
        if ($length < 1 || $length > 1073741823) {
            throw new \ValueError(
                'grapheme_str_split(): Argument #2 ($length) must be greater than 0 and less than or equal to 1073741823.'
            );
        }
        if ('' === $string) {
            return [];
        }
        $graphemes = self::splitGraphemes($string);
        if (null === $graphemes) {
            return false;
        }
        if (1 === $length) {
            return $graphemes;
        }
        $chunks = \array_chunk($graphemes, $length);
        $result = [];
        foreach ($chunks as $chunk) {
            $result[] = \implode('', $chunk);
        }

        return $result;
    }

    /**
     * grapheme_levenshtein() — grapheme-cluster edit distance (php-src ext/intl/grapheme; #6998).
     */
    public static function levenshtein(string $string1, string $string2): int
    {
        $graphemes1 = self::splitGraphemes($string1);
        if (null === $graphemes1) {
            return -1;
        }
        $graphemes2 = self::splitGraphemes($string2);
        if (null === $graphemes2) {
            return -1;
        }

        return self::levenshteinGraphemeArrays($graphemes1, $graphemes2);
    }

    /**
     * @param list<string> $graphemes1
     * @param list<string> $graphemes2
     */
    private static function levenshteinGraphemeArrays(array $graphemes1, array $graphemes2): int
    {
        $len1 = \count($graphemes1);
        $len2 = \count($graphemes2);
        if (0 === $len1) {
            return $len2;
        }
        if (0 === $len2) {
            return $len1;
        }

        $prev = [];
        for ($j = 0; $j <= $len2; ++$j) {
            $prev[$j] = $j;
        }
        for ($i = 1; $i <= $len1; ++$i) {
            $cur = [];
            $cur[0] = $i;
            for ($j = 1; $j <= $len2; ++$j) {
                $subst = self::graphemesEqual($graphemes1[$i - 1], $graphemes2[$j - 1]) ? 0 : 1;
                $cur[$j] = min(
                    $cur[$j - 1] + 1,
                    $prev[$j] + 1,
                    $prev[$j - 1] + $subst
                );
            }
            $prev = $cur;
        }

        return $prev[$len2];
    }

    private static function graphemesEqual(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        return UnicodeCanonical::graphemeCanonicalKey($left) === UnicodeCanonical::graphemeCanonicalKey($right);
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
