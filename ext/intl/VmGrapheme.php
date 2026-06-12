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
    public const EXTR_COUNT = 0;
    public const EXTR_MAXBYTES = 1;
    public const EXTR_MAXCHARS = 2;

    public static function strContains(string $haystack, string $needle): bool
    {
        if ('' === $needle) {
            return true;
        }

        return self::strContainsUtf8($haystack, $needle);
    }

    /**
     * grapheme_strstr() — grapheme-cluster strstr (php-src ext/intl/grapheme; #7221).
     *
     * @return string|false
     */
    public static function strstr(string $haystack, string $needle, bool $beforeNeedle = false): string|false
    {
        if ('' === $needle) {
            return $beforeNeedle ? '' : $haystack;
        }

        return self::strstrUtf8($haystack, $needle, $beforeNeedle, false);
    }

    /**
     * grapheme_stristr() — case-insensitive grapheme strstr (php-src ext/intl/grapheme; #7221).
     *
     * @return string|false
     */
    public static function stristr(string $haystack, string $needle, bool $beforeNeedle = false): string|false
    {
        if ('' === $needle) {
            return $beforeNeedle ? '' : $haystack;
        }

        return self::strstrUtf8($haystack, $needle, $beforeNeedle, true);
    }

    private static function strContainsUtf8(string $haystack, string $needle): bool
    {
        return false !== self::findGraphemeSubsequence($haystack, $needle, false);
    }

    /**
     * @return string|false
     */
    private static function strstrUtf8(
        string $haystack,
        string $needle,
        bool $beforeNeedle,
        bool $caseInsensitive
    ): string|false {
        $matchIndex = self::findGraphemeSubsequence($haystack, $needle, $caseInsensitive);
        if (false === $matchIndex) {
            return false;
        }
        $hay = self::splitGraphemes($haystack);
        if (null === $hay) {
            return false;
        }
        $byteOffset = self::graphemeByteOffset($hay, $matchIndex);
        if ($beforeNeedle) {
            return \substr($haystack, 0, $byteOffset);
        }

        return \substr($haystack, $byteOffset);
    }

    /**
     * @return int|false grapheme index of first match, or false
     */
    private static function findGraphemeSubsequence(
        string $haystack,
        string $needle,
        bool $caseInsensitive
    ): int|false {
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
            return 0;
        }
        for ($i = 0; $i <= $hayLen - $needLen; ++$i) {
            $matched = true;
            for ($j = 0; $j < $needLen; ++$j) {
                if ($caseInsensitive) {
                    if (!self::graphemesEqualInsensitive($hay[$i + $j], $need[$j])) {
                        $matched = false;
                        break;
                    }
                } elseif ($hay[$i + $j] !== $need[$j]) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return $i;
            }
        }

        return false;
    }

    /**
     * @param list<string> $graphemes
     */
    private static function graphemeByteOffset(array $graphemes, int $index): int
    {
        $offset = 0;
        for ($i = 0; $i < $index; ++$i) {
            $offset += \strlen($graphemes[$i]);
        }

        return $offset;
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
     * grapheme_extract() — extract grapheme clusters from UTF-8 buffer (php-src ext/intl/grapheme; #6023).
     */
    public static function extract(
        string $haystack,
        int $size,
        int $extractType = self::EXTR_COUNT,
        int $start = 0,
        ?int &$next = null
    ): string|false {
        if ($extractType < self::EXTR_COUNT || $extractType > self::EXTR_MAXCHARS) {
            return false;
        }
        $strLen = \strlen($haystack);
        if ($start < 0 || $start >= $strLen) {
            return false;
        }
        if ($size < 0) {
            return false;
        }
        if (0 === $size) {
            if (null !== $next) {
                $next = $start;
            }

            return '';
        }

        $adjustedStart = self::advanceOffsetToUtf8Lead($haystack, $start);
        if (false === $adjustedStart) {
            return false;
        }

        $substring = \substr($haystack, $adjustedStart);
        if ('' === $substring) {
            if (null !== $next) {
                $next = $adjustedStart;
            }

            return '';
        }

        if (self::isAscii($substring)) {
            $extractLen = \min($size, \strlen($substring));
            if (null !== $next) {
                $next = $adjustedStart + $extractLen;
            }

            return \substr($substring, 0, $extractLen);
        }

        $graphemes = self::splitGraphemes($substring);
        if (null === $graphemes) {
            return false;
        }

        $result = match ($extractType) {
            self::EXTR_COUNT => self::extractByCount($graphemes, $size),
            self::EXTR_MAXBYTES => self::extractByMaxBytes($graphemes, $size),
            self::EXTR_MAXCHARS => self::extractByMaxChars($graphemes, $size),
        };

        if (null !== $next) {
            $next = $adjustedStart + \strlen($result);
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

    private static function graphemesEqualInsensitive(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        return UnicodeCanonical::graphemeCaseInsensitiveKey($left)
            === UnicodeCanonical::graphemeCaseInsensitiveKey($right);
    }

    /**
     * @param list<string> $graphemes
     */
    private static function extractByCount(array $graphemes, int $count): string
    {
        return \implode('', \array_slice($graphemes, 0, $count));
    }

    /**
     * @param list<string> $graphemes
     */
    private static function extractByMaxBytes(array $graphemes, int $maxBytes): string
    {
        $result = '';
        $bytes = 0;
        foreach ($graphemes as $grapheme) {
            $graphemeBytes = \strlen($grapheme);
            if ($bytes + $graphemeBytes > $maxBytes) {
                break;
            }
            $result .= $grapheme;
            $bytes += $graphemeBytes;
        }

        return $result;
    }

    /**
     * @param list<string> $graphemes
     */
    private static function extractByMaxChars(array $graphemes, int $maxChars): string
    {
        $result = '';
        $chars = 0;
        foreach ($graphemes as $grapheme) {
            $graphemeChars = self::utf16CodeUnitCount($grapheme);
            if ($chars + $graphemeChars > $maxChars) {
                break;
            }
            $result .= $grapheme;
            $chars += $graphemeChars;
        }

        return $result;
    }

    private static function advanceOffsetToUtf8Lead(string $haystack, int $offset): int|false
    {
        $len = \strlen($haystack);
        if ($offset >= $len) {
            return false;
        }
        $pos = $offset;
        while ($pos < $len && self::isUtf8ContinuationByte($haystack[$pos])) {
            ++$pos;
        }
        if ($pos >= $len && $offset < $len) {
            return false;
        }

        return $pos;
    }

    private static function isUtf8ContinuationByte(string $byte): bool
    {
        return 0x80 === (\ord($byte) & 0xC0);
    }

    private static function isAscii(string $string): bool
    {
        return (bool) \preg_match('/^[\x00-\x7F]*$/D', $string);
    }

    private static function utf16CodeUnitCount(string $utf8): int
    {
        $count = 0;
        $len = \strlen($utf8);
        for ($i = 0; $i < $len; ) {
            $byte = \ord($utf8[$i]);
            if ($byte < 0x80) {
                ++$i;
                ++$count;
            } elseif ($byte < 0xE0) {
                $i += 2;
                ++$count;
            } elseif ($byte < 0xF0) {
                $i += 3;
                ++$count;
            } else {
                $i += 4;
                $count += 2;
            }
        }

        return $count;
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
