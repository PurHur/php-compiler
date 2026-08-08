<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\mbstring\EastAsianWidthTable;
use PHPCompiler\ext\mbstring\MbstringEncodingRegistry;
use PHPCompiler\ext\mbstring\VmMbstring;
use PHPCompiler\ext\standard\VmString;

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
     * grapheme_strlen() — count grapheme clusters (php-src ext/intl/grapheme; #5914).
     *
     * @return int|false grapheme count, or false on invalid UTF-8
     */
    public static function strlen(string $string): int|false
    {
        if ('' === $string) {
            return 0;
        }
        $graphemes = self::splitGraphemes($string);
        if (null === $graphemes) {
            return false;
        }

        return \count($graphemes);
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
     * grapheme_substr() — slice by grapheme cluster index (php-src ext/intl/grapheme; #3352).
     *
     * @return string|false substring, or false on invalid UTF-8
     */
    public static function substr(string $string, int $start, ?int $length = null): string|false
    {
        if ('' === $string) {
            return '';
        }
        $graphemes = self::splitGraphemes($string);
        if (null === $graphemes) {
            return false;
        }
        $graphemeCount = \count($graphemes);
        if ($start < 0) {
            $start += $graphemeCount;
        }
        if ($start < 0) {
            $start = 0;
        }
        if ($start >= $graphemeCount) {
            return '';
        }
        if (null === $length) {
            $slice = \array_slice($graphemes, $start);
        } else {
            if ($length < 0) {
                $length = $graphemeCount - $start + $length;
            }
            if ($length <= 0) {
                return '';
            }
            $slice = \array_slice($graphemes, $start, $length);
        }

        return \implode('', $slice);
    }

    /**
     * grapheme_strpos() — grapheme index search (php-src ext/intl/grapheme; #3352).
     *
     * @return int|false grapheme index of first match
     */
    public static function strpos(string $haystack, string $needle, int $offset = 0): int|false
    {
        if ('' === $needle) {
            return false;
        }

        return self::graphemePosSearch($haystack, $needle, $offset, false, false);
    }

    /**
     * grapheme_stripos() — case-insensitive grapheme index search (php-src ext/intl/grapheme; #6153).
     *
     * @return int|false grapheme index of first match
     */
    public static function stripos(string $haystack, string $needle, int $offset = 0): int|false
    {
        if ('' === $needle) {
            return false;
        }

        return self::graphemePosSearch($haystack, $needle, $offset, true, false);
    }

    /**
     * grapheme_strrpos() — reverse grapheme index search (php-src ext/intl/grapheme; #6153).
     *
     * @return int|false grapheme index of last match
     */
    public static function strrpos(string $haystack, string $needle, int $offset = 0): int|false
    {
        if ('' === $needle) {
            return false;
        }

        return self::graphemePosSearch($haystack, $needle, $offset, false, true);
    }

    /**
     * grapheme_strripos() — case-insensitive reverse grapheme index search (php-src ext/intl/grapheme; #20810).
     *
     * @return int|false grapheme index of last match
     */
    public static function strripos(string $haystack, string $needle, int $offset = 0): int|false
    {
        if ('' === $needle) {
            return false;
        }

        return self::graphemePosSearch($haystack, $needle, $offset, true, true);
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
        return self::graphemePosSearch($haystack, $needle, 0, $caseInsensitive, false);
    }

    /**
     * @return int|false grapheme index
     */
    private static function graphemePosSearch(
        string $haystack,
        string $needle,
        int $offset,
        bool $caseInsensitive,
        bool $reverse
    ): int|false {
        $hay = self::splitGraphemes($haystack);
        if (null === $hay) {
            return false;
        }
        $need = self::splitGraphemes($needle);
        if (null === $need) {
            return false;
        }
        $start = self::normalizeGraphemeSearchOffset($offset, \count($hay));
        if (false === $start) {
            return false;
        }

        return self::findGraphemeSubsequenceAt($hay, $need, $start, $caseInsensitive, $reverse);
    }

    /**
     * @return int|false normalized start grapheme index
     */
    private static function normalizeGraphemeSearchOffset(int $offset, int $hayLen): int|false
    {
        if ($offset < 0) {
            $offset += $hayLen;
        }
        if ($offset < 0 || $offset > $hayLen) {
            return false;
        }

        return $offset;
    }

    /**
     * @param list<string> $hay
     * @param list<string> $need
     *
     * @return int|false grapheme index
     */
    private static function findGraphemeSubsequenceAt(
        array $hay,
        array $need,
        int $startIndex,
        bool $caseInsensitive,
        bool $reverse
    ): int|false {
        $hayLen = \count($hay);
        $needLen = \count($need);
        if (0 === $needLen) {
            return 0;
        }
        if ($needLen > $hayLen) {
            return false;
        }
        if ($reverse) {
            for ($i = $hayLen - $needLen; $i >= $startIndex; --$i) {
                if (self::graphemeSubsequenceMatchesAt($hay, $need, $i, $caseInsensitive)) {
                    return $i;
                }
            }
        } else {
            for ($i = $startIndex; $i <= $hayLen - $needLen; ++$i) {
                if (self::graphemeSubsequenceMatchesAt($hay, $need, $i, $caseInsensitive)) {
                    return $i;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $hay
     * @param list<string> $need
     */
    private static function graphemeSubsequenceMatchesAt(
        array $hay,
        array $need,
        int $index,
        bool $caseInsensitive
    ): bool {
        $needLen = \count($need);
        for ($j = 0; $j < $needLen; ++$j) {
            if ($caseInsensitive) {
                if (!self::graphemesEqualInsensitive($hay[$index + $j], $need[$j])) {
                    return false;
                }
            } elseif (!self::graphemesEqual($hay[$index + $j], $need[$j])) {
                return false;
            }
        }

        return true;
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
     * grapheme_levenshtein() — grapheme-cluster edit distance (php-src ext/intl/grapheme_string.c; #6998, #27591).
     *
     * @return int|false distance, or false when input is not valid UTF-8
     */
    public static function levenshtein(
        string $string1,
        string $string2,
        int $insertionCost = 1,
        int $replacementCost = 1,
        int $deletionCost = 1
    ): int|false {
        $graphemes1 = self::splitGraphemes($string1);
        if (null === $graphemes1) {
            return false;
        }
        $graphemes2 = self::splitGraphemes($string2);
        if (null === $graphemes2) {
            return false;
        }

        return self::levenshteinGraphemeArrays(
            $graphemes1,
            $graphemes2,
            $insertionCost,
            $replacementCost,
            $deletionCost
        );
    }

    /**
     * @param list<string> $graphemes1
     * @param list<string> $graphemes2
     */
    private static function levenshteinGraphemeArrays(
        array $graphemes1,
        array $graphemes2,
        int $insertionCost = 1,
        int $replacementCost = 1,
        int $deletionCost = 1
    ): int {
        $len1 = \count($graphemes1);
        $len2 = \count($graphemes2);
        if (0 === $len1) {
            return $len2 * $insertionCost;
        }
        if (0 === $len2) {
            return $len1 * $deletionCost;
        }

        // php-src: when all costs equal and string1 shorter, swap to save memory/CPU.
        if ($len1 < $len2
            && $insertionCost === $replacementCost
            && $replacementCost === $deletionCost
        ) {
            $tmp = $graphemes1;
            $graphemes1 = $graphemes2;
            $graphemes2 = $tmp;
            $len1 = \count($graphemes1);
            $len2 = \count($graphemes2);
        }

        $prev = [];
        for ($j = 0; $j <= $len2; ++$j) {
            $prev[$j] = $j * $insertionCost;
        }
        for ($i = 1; $i <= $len1; ++$i) {
            $cur = [];
            $cur[0] = $i * $deletionCost;
            for ($j = 1; $j <= $len2; ++$j) {
                $subst = self::graphemesEqual($graphemes1[$i - 1], $graphemes2[$j - 1])
                    ? 0
                    : $replacementCost;
                $cur[$j] = min(
                    $cur[$j - 1] + $insertionCost,
                    $prev[$j] + $deletionCost,
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

    /**
     * grapheme_strimwidth() — trim by display width in grapheme clusters (php-src ext/intl/grapheme; #9793, #17342).
     */
    public static function strimwidth(
        string $string,
        int $start,
        int $width,
        ?string $encoding = null
    ): string|false {
        $encoding = MbstringEncodingRegistry::assertValid(
            null === $encoding ? 'UTF-8' : $encoding,
            'grapheme_strimwidth',
            3
        );
        VmMbstring::assertSubstrCountEncoding($encoding, 'grapheme_strimwidth', 3);
        if ('' === $string) {
            return '';
        }
        $graphemes = self::splitGraphemes($string);
        if (null === $graphemes) {
            return false;
        }
        $graphemeCount = \count($graphemes);
        if ($start < 0) {
            $start += $graphemeCount;
        }
        if ($start < 0) {
            $start = 0;
        }
        if ($start >= $graphemeCount) {
            return '';
        }
        $graphemes = \array_slice($graphemes, $start);
        $totalWidth = self::graphemesDisplayWidth($graphemes);
        if ($width < 0) {
            $width = $totalWidth + $width;
            if ($width < 0) {
                throw new \ValueError('grapheme_strimwidth(): Argument #3 ($width) is out of range');
            }
        }
        if ($totalWidth <= $width) {
            return \implode('', $graphemes);
        }

        return self::trimGraphemesToWidth($graphemes, $width);
    }

    /**
     * @param list<string> $graphemes
     */
    private static function graphemesDisplayWidth(array $graphemes): int
    {
        $width = 0;
        foreach ($graphemes as $grapheme) {
            $width += self::graphemeClusterWidth($grapheme);
        }

        return $width;
    }

    private static function graphemeClusterWidth(string $grapheme): int
    {
        $width = 0;
        $charLen = VmString::utf8CharLength($grapheme);
        for ($i = 0; $i < $charLen; ++$i) {
            $width += EastAsianWidthTable::characterWidth(
                VmMbstring::utf8CharToCodepoint(VmString::utf8CharSubstr($grapheme, $i, 1))
            );
        }

        return $width;
    }

    /**
     * @param list<string> $graphemes
     */
    private static function trimGraphemesToWidth(array $graphemes, int $contentWidth): string
    {
        if ($contentWidth <= 0) {
            return '';
        }
        $used = 0;
        $out = '';
        foreach ($graphemes as $grapheme) {
            $graphemeWidth = self::graphemeClusterWidth($grapheme);
            if ($used + $graphemeWidth > $contentWidth) {
                break;
            }
            $out .= $grapheme;
            $used += $graphemeWidth;
        }

        return $out;
    }
}
