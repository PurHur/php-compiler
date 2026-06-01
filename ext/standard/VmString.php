<?php

declare(strict_types=1);

/**
 * VM-runtime string helpers for the standard library (no PHP userland builtins).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

final class VmString
{
    public const TRIM_DEFAULT = " \t\n\r\0\x0B";

    /**
     * Coerce a string-search builtin operand to string (php-src _convert_to_string parity, #3549).
     */
    public static function coerceOperand(Variable $var): string
    {
        return $var->resolveIndirect()->toString();
    }

    /** Regex metacharacters escaped by preg_quote() (PHP 8.2 byte subset). */
    private const PREG_QUOTE_ESCAPE = '.\\+*?[^]()$={}-|!<>:';

    /** Metacharacters escaped by quotemeta() (PHP 8.2 byte subset). */
    private const QUOTEMETA_ESCAPE = '.\\+*?[]^()$';

    public static function byteLength(string $string): int
    {
        $len = 0;
        while (isset($string[$len])) {
            ++$len;
        }

        return $len;
    }

    /**
     * UTF-8 codepoint count for BMP web text (issue #158). Invalid bytes count as one character.
     */
    public static function utf8CharLength(string $string): int
    {
        $byteLen = self::byteLength($string);
        $count = 0;
        for ($i = 0; $i < $byteLen; ++$count) {
            $byte = \ord($string[$i]);
            if ($byte < 0x80) {
                $i += 1;
            } elseif (($byte & 0xE0) === 0xC0 && $i + 1 < $byteLen) {
                $i += 2;
            } elseif (($byte & 0xF0) === 0xE0 && $i + 2 < $byteLen) {
                $i += 3;
            } elseif (($byte & 0xF8) === 0xF0 && $i + 3 < $byteLen) {
                $i += 4;
            } else {
                $i += 1;
            }
        }

        return $count;
    }

    public static function byteSlice(string $string, int $offset, ?int $length = null): string
    {
        $len = self::byteLength($string);
        if ($offset < 0) {
            $offset = $len + $offset;
            if ($offset < 0) {
                $offset = 0;
            }
        }
        if ($offset > $len) {
            return '';
        }
        if (null === $length) {
            $length = $len - $offset;
        } elseif ($length < 0) {
            $length = $len - $offset + $length;
            if ($length < 0) {
                return '';
            }
        }
        if ($offset + $length > $len) {
            $length = $len - $offset;
        }
        $out = '';
        for ($i = 0; $i < $length; ++$i) {
            $out .= $string[$offset + $i];
        }

        return $out;
    }

    public static function strrev(string $string): string
    {
        $len = self::byteLength($string);
        $out = '';
        for ($i = $len - 1; $i >= 0; --$i) {
            $out .= $string[$i];
        }

        return $out;
    }

    /**
     * str_shuffle() — Fisher–Yates on bytes (CSPRNG via {@see randomBytes()}).
     */
    public static function strShuffle(string $string): string
    {
        $len = self::byteLength($string);
        if ($len < 2) {
            return $string;
        }
        $chars = [];
        for ($i = 0; $i < $len; ++$i) {
            $chars[$i] = $string[$i];
        }
        for ($i = $len - 1; $i > 0; --$i) {
            $rand = self::randomBytes(8);
            $pick = 0;
            for ($b = 0; $b < 8; ++$b) {
                $pick = ($pick << 8) | self::byteOrd($rand[$b]);
            }
            $j = $pick % ($i + 1);
            if ($j < 0) {
                $j += $i + 1;
            }
            $tmp = $chars[$i];
            $chars[$i] = $chars[$j];
            $chars[$j] = $tmp;
        }

        return \implode('', $chars);
    }

    /** chunk_split() — chunk string and append separator after each chunk (PHP semantics). */
    public static function chunkSplit(string $string, int $length, string $separator = "\r\n"): string
    {
        if ($length < 1) {
            throw new \ValueError('chunk_split(): Argument #2 ($length) must be greater than 0');
        }
        $byteLen = self::byteLength($string);
        if (0 === $byteLen) {
            return '';
        }
        $out = '';
        for ($i = 0; $i < $byteLen; $i += $length) {
            $out .= self::byteSlice($string, $i, $length);
            $out .= $separator;
        }

        return $out;
    }

    /** wordwrap() — wrap string to width at spaces (PHP semantics; byte-oriented subset). */
    public static function wordwrap(string $text, int $width = 75, string $break = "\n", bool $cut = false): string
    {
        $len = self::byteLength($text);
        if (0 === $len) {
            return '';
        }
        $breakLen = self::byteLength($break);
        if (0 === $breakLen) {
            throw new \ValueError('wordwrap(): Argument #3 ($break) must be a non-empty string');
        }
        if (0 === $width && $cut) {
            throw new \ValueError('wordwrap(): Argument #4 ($cut) cannot be true when argument #2 ($width) is 0');
        }

        if ($cut) {
            return self::wordwrapCutFixedWidth($text, $len, $width, $break);
        }
        if (1 === $breakLen) {
            return self::wordwrapFastSingleByteBreak($text, $len, $width, $break[0]);
        }

        return self::wordwrapGeneral($text, $len, $width, $break, $breakLen);
    }

    /** cut=true — fixed-width chunks with full $break between segments (php-src php_wordwrap). */
    private static function wordwrapCutFixedWidth(string $text, int $len, int $width, string $break): string
    {
        if ($width < 1) {
            return $text;
        }
        $out = '';
        for ($i = 0; $i < $len; $i += $width) {
            if ($i > 0) {
                $out .= $break;
            }
            $out .= self::byteSlice($text, $i, $width);
        }

        return $out;
    }

    /** Fast path: single-byte break, cut=false (while/continue CFG for AOT self-host). */
    private static function wordwrapFastSingleByteBreak(string $text, int $len, int $width, string $breakByte): string
    {
        $chars = [];
        for ($i = 0; $i < $len; ++$i) {
            $chars[$i] = $text[$i];
        }
        $laststart = 0;
        $lastspace = 0;
        for ($current = 0; $current < $len; ++$current) {
            $ch = $chars[$current];
            if ($ch === $breakByte) {
                $laststart = $current + 1;
                $lastspace = $current + 1;
            }
            if ($ch !== $breakByte && ' ' === $ch) {
                if ($current - $laststart >= $width) {
                    $chars[$current] = $breakByte;
                    $laststart = $current + 1;
                }
                $lastspace = $current;
            }
            if ($ch !== $breakByte && ' ' !== $ch && $current - $laststart >= $width && $laststart !== $lastspace) {
                $chars[$lastspace] = $breakByte;
                $laststart = $lastspace + 1;
            }
        }

        return \implode('', $chars);
    }

    /** General path: multi-byte break, cut=false (space wrapping with explicit break match). */
    private static function wordwrapGeneral(
        string $text,
        int $len,
        int $width,
        string $break,
        int $breakLen
    ): string {
        $pieces = [];
        $laststart = 0;
        $lastspace = 0;
        $current = 0;
        while ($current < $len) {
            if ($current + $breakLen <= $len
                && $text[$current] === $break[0]
                && 0 === self::byteCompareN($text, $current, $break, 0, $breakLen)) {
                $pieces[] = self::byteSlice($text, $laststart, $current - $laststart + $breakLen);
                $current += $breakLen;
                $laststart = $current;
                $lastspace = $current;
            } elseif (' ' === $text[$current]) {
                if ($current - $laststart >= $width) {
                    $pieces[] = self::byteSlice($text, $laststart, $current - $laststart);
                    $pieces[] = $break;
                    $laststart = $current + 1;
                }
                $lastspace = $current;
                ++$current;
            } elseif ($current - $laststart >= $width && $laststart < $lastspace) {
                $pieces[] = self::byteSlice($text, $laststart, $lastspace - $laststart);
                $pieces[] = $break;
                $laststart = $lastspace + 1;
                $lastspace = $laststart;
                ++$current;
            } else {
                ++$current;
            }
        }
        if ($laststart < $len) {
            $pieces[] = self::byteSlice($text, $laststart, $len - $laststart);
        }

        return \implode('', $pieces);
    }

    private static function byteReplaceAt(string $string, int $offset, string $byte): string
    {
        if ($offset < 0 || $offset >= self::byteLength($string)) {
            return $string;
        }
        $prefix = 0 === $offset ? '' : self::byteSlice($string, 0, $offset);
        $suffix = self::byteSlice($string, $offset + 1);

        return $prefix . $byte . $suffix;
    }

    private static function byteCompareN(string $a, int $aOff, string $b, int $bOff, int $n): int
    {
        for ($i = 0; $i < $n; ++$i) {
            if ($a[$aOff + $i] !== $b[$bOff + $i]) {
                return 1;
            }
        }

        return 0;
    }

    public static function strcmp(string $a, string $b): int
    {
        $lenA = self::byteLength($a);
        $lenB = self::byteLength($b);
        $min = $lenA < $lenB ? $lenA : $lenB;
        for ($i = 0; $i < $min; ++$i) {
            $ordA = self::byteOrd($a[$i]);
            $ordB = self::byteOrd($b[$i]);
            if ($ordA !== $ordB) {
                return $ordA <=> $ordB;
            }
        }

        return $lenA <=> $lenB;
    }

    /**
     * strnatcmp() — byte-oriented natural order (subset of PHP; issue #2358).
     */
    public static function strnatcmp(string $a, string $b): int
    {
        $lenA = self::byteLength($a);
        $lenB = self::byteLength($b);
        $ia = 0;
        $ib = 0;
        while ($ia < $lenA && $ib < $lenB) {
            $ordA = self::byteOrd($a[$ia]);
            $ordB = self::byteOrd($b[$ib]);
            $digA = $ordA >= 48 && $ordA <= 57;
            $digB = $ordB >= 48 && $ordB <= 57;
            if ($digA && $digB) {
                while ($ia < $lenA && 48 === self::byteOrd($a[$ia])) {
                    ++$ia;
                }
                while ($ib < $lenB && 48 === self::byteOrd($b[$ib])) {
                    ++$ib;
                }
                $startA = $ia;
                $startB = $ib;
                while ($ia < $lenA) {
                    $o = self::byteOrd($a[$ia]);
                    if ($o < 48 || $o > 57) {
                        break;
                    }
                    ++$ia;
                }
                while ($ib < $lenB) {
                    $o = self::byteOrd($b[$ib]);
                    if ($o < 48 || $o > 57) {
                        break;
                    }
                    ++$ib;
                }
                $numLenA = $ia - $startA;
                $numLenB = $ib - $startB;
                if (0 === $numLenA && 0 === $numLenB) {
                    continue;
                }
                if ($numLenA !== $numLenB) {
                    return $numLenA <=> $numLenB;
                }
                for ($k = 0; $k < $numLenA; ++$k) {
                    $da = self::byteOrd($a[$startA + $k]);
                    $db = self::byteOrd($b[$startB + $k]);
                    if ($da !== $db) {
                        return $da <=> $db;
                    }
                }

                continue;
            }
            if ($ordA !== $ordB) {
                return $ordA <=> $ordB;
            }
            ++$ia;
            ++$ib;
        }

        return ($lenA - $ia) <=> ($lenB - $ib);
    }

    /**
     * strnatcasecmp() — byte-oriented natural order, ASCII case-insensitive (#2372).
     */
    public static function strnatcasecmp(string $a, string $b): int
    {
        $lenA = self::byteLength($a);
        $lenB = self::byteLength($b);
        $ia = 0;
        $ib = 0;
        while ($ia < $lenA && $ib < $lenB) {
            $ordA = self::byteOrd($a[$ia]);
            $ordB = self::byteOrd($b[$ib]);
            $digA = $ordA >= 48 && $ordA <= 57;
            $digB = $ordB >= 48 && $ordB <= 57;
            if ($digA && $digB) {
                while ($ia < $lenA && 48 === self::byteOrd($a[$ia])) {
                    ++$ia;
                }
                while ($ib < $lenB && 48 === self::byteOrd($b[$ib])) {
                    ++$ib;
                }
                $startA = $ia;
                $startB = $ib;
                while ($ia < $lenA) {
                    $o = self::byteOrd($a[$ia]);
                    if ($o < 48 || $o > 57) {
                        break;
                    }
                    ++$ia;
                }
                while ($ib < $lenB) {
                    $o = self::byteOrd($b[$ib]);
                    if ($o < 48 || $o > 57) {
                        break;
                    }
                    ++$ib;
                }
                $numLenA = $ia - $startA;
                $numLenB = $ib - $startB;
                if (0 === $numLenA && 0 === $numLenB) {
                    continue;
                }
                if ($numLenA !== $numLenB) {
                    return $numLenA <=> $numLenB;
                }
                for ($k = 0; $k < $numLenA; ++$k) {
                    $da = self::byteOrd($a[$startA + $k]);
                    $db = self::byteOrd($b[$startB + $k]);
                    if ($da !== $db) {
                        return $da <=> $db;
                    }
                }

                continue;
            }
            $ordA = self::byteOrd(self::asciiLowerByte($a[$ia]));
            $ordB = self::byteOrd(self::asciiLowerByte($b[$ib]));
            if ($ordA !== $ordB) {
                return $ordA <=> $ordB;
            }
            ++$ia;
            ++$ib;
        }

        return ($lenA - $ia) <=> ($lenB - $ib);
    }

    public static function strncmp(string $a, string $b, int $length): int
    {
        if ($length <= 0) {
            return 0;
        }
        $lenA = self::byteLength($a);
        $lenB = self::byteLength($b);
        $compare = $length;
        if ($compare > $lenA) {
            $compare = $lenA;
        }
        if ($compare > $lenB) {
            $compare = $lenB;
        }
        for ($i = 0; $i < $compare; ++$i) {
            $ordA = self::byteOrd($a[$i]);
            $ordB = self::byteOrd($b[$i]);
            if ($ordA !== $ordB) {
                return $ordA <=> $ordB;
            }
        }

        return 0;
    }

    public static function strcasecmp(string $a, string $b): int
    {
        $lenA = self::byteLength($a);
        $lenB = self::byteLength($b);
        $min = $lenA < $lenB ? $lenA : $lenB;
        for ($i = 0; $i < $min; ++$i) {
            $ordA = self::byteOrd(self::asciiLowerByte($a[$i]));
            $ordB = self::byteOrd(self::asciiLowerByte($b[$i]));
            if ($ordA !== $ordB) {
                return $ordA <=> $ordB;
            }
        }

        return $lenA <=> $lenB;
    }

    public static function strncasecmp(string $a, string $b, int $length): int
    {
        if ($length <= 0) {
            return 0;
        }
        $lenA = self::byteLength($a);
        $lenB = self::byteLength($b);
        $compare = $length;
        if ($compare > $lenA) {
            $compare = $lenA;
        }
        if ($compare > $lenB) {
            $compare = $lenB;
        }
        for ($i = 0; $i < $compare; ++$i) {
            $ordA = self::byteOrd(self::asciiLowerByte($a[$i]));
            $ordB = self::byteOrd(self::asciiLowerByte($b[$i]));
            if ($ordA !== $ordB) {
                return $ordA <=> $ordB;
            }
        }

        return 0;
    }

    /**
     * substr_compare() — byte-oriented haystack slice vs needle (subset of PHP; issue #2400).
     */
    public static function substr_compare(
        string $haystack,
        string $needle,
        int $offset,
        ?int $length = null,
        bool $caseInsensitive = false
    ): int {
        $hayLen = self::byteLength($haystack);
        if ($offset < 0) {
            $offset += $hayLen;
            if ($offset < 0) {
                $offset = 0;
            }
        }
        if ($offset > $hayLen) {
            throw new \ValueError('substr_compare(): Argument #3 ($offset) must be contained in argument #1 ($haystack)');
        }
        $needleLen = self::byteLength($needle);
        $hayRemain = $hayLen - $offset;
        $compareLen = $hayRemain;
        $lengthOmitted = null === $length;
        if (!$lengthOmitted) {
            if ($length < 0) {
                throw new \ValueError('substr_compare(): Argument #4 ($length) must be greater than or equal to 0');
            }
            if ($length > $hayRemain) {
                $length = $hayRemain;
            }
            $compareLen = $length;
        } else {
            $length = $needleLen > $hayRemain ? $hayRemain : $needleLen;
        }
        $s1 = self::byteSlice($haystack, $offset, $length);
        $cmp = $caseInsensitive
            ? self::strncmpCase($s1, $needle, $length)
            : self::strncmp($s1, $needle, $length);
        if (0 !== $cmp) {
            return $cmp;
        }
        if ($lengthOmitted && $compareLen !== $needleLen) {
            return $compareLen < $needleLen ? -1 : 1;
        }

        return 0;
    }

    /**
     * levenshtein() — byte-oriented edit distance (subset of PHP; issue #2406).
     */
    public static function levenshtein(
        string $string1,
        string $string2,
        int $insertionCost = 1,
        int $replacementCost = 1,
        int $deletionCost = 1
    ): int {
        $len1 = self::byteLength($string1);
        $len2 = self::byteLength($string2);
        if ($len1 > 255 || $len2 > 255) {
            throw new \ValueError(
                'levenshtein(): Argument #1 ($string1) or #2 ($string2) must be less than 256 characters'
            );
        }
        if ($insertionCost < 1 || $replacementCost < 1 || $deletionCost < 1) {
            throw new \ValueError(
                'levenshtein(): insertion, replacement, and deletion costs must be larger than zero'
            );
        }
        if (0 === $len1) {
            return $len2 * $insertionCost;
        }
        if (0 === $len2) {
            return $len1 * $deletionCost;
        }

        $prev = [];
        for ($j = 0; $j <= $len2; ++$j) {
            $prev[$j] = $j * $insertionCost;
        }
        for ($i = 1; $i <= $len1; ++$i) {
            $cur = [];
            $cur[0] = $i * $deletionCost;
            for ($j = 1; $j <= $len2; ++$j) {
                $subst = $string1[$i - 1] === $string2[$j - 1] ? 0 : $replacementCost;
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

    /**
     * similar_text() — PHP-compatible Oliver algorithm (issue #2445).
     */
    public static function similar_text(string $string1, string $string2, ?float &$percent = null): int
    {
        $len1 = self::byteLength($string1);
        $len2 = self::byteLength($string2);
        if ($len1 > 255 || $len2 > 255) {
            throw new \ValueError(
                'similar_text(): Argument #1 ($string1) or #2 ($string2) must be less than 256 characters'
            );
        }
        if (0 === $len1 && 0 === $len2) {
            if (null !== $percent) {
                $percent = 0.0;
            }

            return 0;
        }
        $sim = self::similarChar($string1, $len1, $string2, $len2);
        if (null !== $percent) {
            $percent = $sim * 200.0 / ($len1 + $len2);
        }

        return $sim;
    }

    private static function similarStr(
        string $txt1,
        int $len1,
        string $txt2,
        int $len2,
        int &$pos1,
        int &$pos2,
        int &$max,
        int &$count
    ): void {
        $max = 0;
        $count = 0;
        for ($p = 0; $p < $len1; ++$p) {
            for ($q = 0; $q < $len2; ++$q) {
                $l = 0;
                while (
                    $p + $l < $len1
                    && $q + $l < $len2
                    && $txt1[$p + $l] === $txt2[$q + $l]
                ) {
                    ++$l;
                }
                if ($l > $max) {
                    $max = $l;
                    ++$count;
                    $pos1 = $p;
                    $pos2 = $q;
                }
            }
        }
    }

    private static function similarChar(string $txt1, int $len1, string $txt2, int $len2): int
    {
        $pos1 = 0;
        $pos2 = 0;
        $max = 0;
        $count = 0;
        self::similarStr($txt1, $len1, $txt2, $len2, $pos1, $pos2, $max, $count);
        $sum = $max;
        if ($sum > 0) {
            if ($pos1 > 0 && $pos2 > 0 && $count > 1) {
                $sum += self::similarChar(
                    substr($txt1, 0, $pos1),
                    $pos1,
                    substr($txt2, 0, $pos2),
                    $pos2
                );
            }
            if ($pos1 + $max < $len1 && $pos2 + $max < $len2) {
                $sum += self::similarChar(
                    substr($txt1, $pos1 + $max),
                    $len1 - $pos1 - $max,
                    substr($txt2, $pos2 + $max),
                    $len2 - $pos2 - $max
                );
            }
        }

        return $sum;
    }

    /**
     * metaphone() — PHP-compatible Metaphone on ASCII letters (issue #2423).
     */
    public static function metaphone(string $string, int $maxPhonemes = 0): string
    {
        return VmMetaphone::encode($string, $maxPhonemes);
    }

    /**
     * soundex() — PHP-compatible Soundex on ASCII letters (issue #2416).
     */
    public static function soundex(string $string): string
    {
        /** @var list<int|string> PHP 8 soundex_table[26]: 0 = vowel/H/W, else digit char */
        static $table = [
            0, '1', '2', '3', 0, '1', '2', 0, 0, '2', '2', '4', '5', '5', 0, '1', '2', '6', '2', '3', 0, '1', 0, '2', 0, '2',
        ];
        $code = '';
        $last = 0;
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ord = self::byteOrd($string[$i]);
            if ($ord >= 97 && $ord <= 122) {
                $ord -= 32;
            }
            if ($ord < 65 || $ord > 90) {
                continue;
            }
            $upper = self::byteChr($ord);
            $digit = $table[$ord - 65];
            if ('' === $code) {
                $code = $upper;
                $last = $digit;
                continue;
            }
            if ($digit !== $last) {
                if (0 !== $digit && self::byteLength($code) < 4) {
                    $code .= (string) $digit;
                }
                $last = $digit;
            }
        }
        if ('' === $code) {
            return '0000';
        }

        return str_pad($code, 4, '0');
    }

    private static function strncmpCase(string $a, string $b, int $length): int
    {
        if ($length <= 0) {
            return 0;
        }
        $lenA = self::byteLength($a);
        $lenB = self::byteLength($b);
        $compare = $length;
        if ($compare > $lenA) {
            $compare = $lenA;
        }
        if ($compare > $lenB) {
            $compare = $lenB;
        }
        for ($i = 0; $i < $compare; ++$i) {
            $ordA = self::byteOrd(self::asciiLowerByte($a[$i]));
            $ordB = self::byteOrd(self::asciiLowerByte($b[$i]));
            if ($ordA !== $ordB) {
                return $ordA <=> $ordB;
            }
        }

        return 0;
    }

    /**
     * @return array{0: int, 1: int} start offset and segment length (php_spn_common_handler)
     */
    private static function normalizeSpnBounds(int $strLen, int $start, ?int $length): array
    {
        $remainLen = $strLen;
        if ($start < 0) {
            $start += $remainLen;
            if ($start < 0) {
                $start = 0;
            }
        } elseif ($start > $remainLen) {
            $start = $remainLen;
        }
        $remainLen -= $start;
        if (null === $length) {
            $length = $remainLen;
        } elseif ($length < 0) {
            $length += $remainLen;
            if ($length < 0) {
                $length = 0;
            }
        } elseif ($length > $remainLen) {
            $length = $remainLen;
        }

        return [$start, $length];
    }

    public static function strspn(string $str, string $mask, int $offset = 0, ?int $length = null): int
    {
        $slen = self::byteLength($str);
        [$start, $len] = self::normalizeSpnBounds($slen, $offset, $length);
        if ('' === $mask || 0 === $len) {
            return 0;
        }
        $mlen = self::byteLength($mask);
        $count = 0;
        for ($i = $start; $i < $start + $len; ++$i) {
            if (!self::byteInSet($str[$i], $mask, $mlen)) {
                break;
            }
            ++$count;
        }

        return $count;
    }

    public static function strcspn(string $str, string $mask, int $offset = 0, ?int $length = null): int
    {
        $slen = self::byteLength($str);
        [$start, $len] = self::normalizeSpnBounds($slen, $offset, $length);
        if (0 === $len) {
            return 0;
        }
        if ('' === $mask) {
            return $len;
        }
        $mlen = self::byteLength($mask);
        $count = 0;
        for ($i = $start; $i < $start + $len; ++$i) {
            if (self::byteInSet($str[$i], $mask, $mlen)) {
                break;
            }
            ++$count;
        }

        return $count;
    }

    public static function strpbrk(string $str, string $mask) {
        if ('' === $mask) {
            throw new \ValueError('strpbrk(): Argument #2 ($characters) must not be empty');
        }
        $slen = self::byteLength($str);
        $mlen = self::byteLength($mask);
        for ($i = 0; $i < $slen; ++$i) {
            if (self::byteInSet($str[$i], $mask, $mlen)) {
                return self::byteSlice($str, $i);
            }
        }

        return false;
    }

    private static function byteInSet(string $byte, string $mask, int $maskLen): bool
    {
        for ($j = 0; $j < $maskLen; ++$j) {
            if ($byte === $mask[$j]) {
                return true;
            }
        }

        return false;
    }

    public static function bin2hex(string $data): string
    {
        $hex = '0123456789abcdef';
        $len = self::byteLength($data);
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $ord = self::byteOrd($data[$i]);
            $out .= $hex[$ord >> 4];
            $out .= $hex[$ord & 0x0F];
        }

        return $out;
    }

    /** Decode a hex string to binary (PHP hex2bin subset; false on invalid input). */
    public static function hex2bin(string $data) {
        $len = self::byteLength($data);
        if (0 === $len) {
            return '';
        }
        if (0 !== ($len & 1)) {
            return false;
        }
        $out = '';
        for ($i = 0; $i < $len; $i += 2) {
            $hi = self::hexDigit(self::byteOrd($data[$i]));
            $lo = self::hexDigit(self::byteOrd($data[$i + 1]));
            if (null === $hi || null === $lo) {
                return false;
            }
            $out .= \chr(($hi << 4) | $lo);
        }

        return $out;
    }

    private const BASE64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /** @var array|null */
    private static ?array $base64DecodeTable = null;

    /** RFC 4648 base64 encode (standard alphabet, padding). */
    public static function base64_encode(string $data): string
    {
        $len = self::byteLength($data);
        if (0 === $len) {
            return '';
        }
        $alphabet = self::BASE64_ALPHABET;
        $out = '';
        for ($i = 0; $i < $len; $i += 3) {
            $b0 = self::byteOrd($data[$i]);
            $b1 = ($i + 1 < $len) ? self::byteOrd($data[$i + 1]) : 0;
            $b2 = ($i + 2 < $len) ? self::byteOrd($data[$i + 2]) : 0;
            $n = ($b0 << 16) | ($b1 << 8) | $b2;
            $out .= $alphabet[($n >> 18) & 63];
            $out .= $alphabet[($n >> 12) & 63];
            if ($i + 1 < $len) {
                $out .= $alphabet[($n >> 6) & 63];
            } else {
                $out .= '=';
            }
            if ($i + 2 < $len) {
                $out .= $alphabet[$n & 63];
            } else {
                $out .= '=';
            }
        }

        return $out;
    }

    /**
     * RFC 4648 base64 decode (non-strict: ignore bytes outside the alphabet).
     *
     * @return string|false
     * decoded bytes, or false when padding or input is invalid
     */
    public static function base64_decode(string $data) {
        $len = self::byteLength($data);
        if (0 === $len) {
            return '';
        }
        if (null === self::$base64DecodeTable) {
            $decode = array_fill(0, 256, -1);
            $alphabet = self::BASE64_ALPHABET;
            for ($i = 0; $i < 64; ++$i) {
                $decode[self::byteOrd($alphabet[$i])] = $i;
            }
            self::$base64DecodeTable = $decode;
        }
        $decode = self::$base64DecodeTable;
        $out = '';
        $val = 0;
        $bits = 0;
        $sawPad = false;
        for ($i = 0; $i < $len; ++$i) {
            $ch = self::byteOrd($data[$i]);
            if (61 === $ch) {
                if ($sawPad) {
                    return false;
                }
                $sawPad = true;
                continue;
            }
            if ($sawPad) {
                return false;
            }
            $d = $decode[$ch] ?? -1;
            if ($d < 0) {
                continue;
            }
            $val = ($val << 6) | $d;
            $bits += 6;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= \chr(($val >> $bits) & 0xFF);
                $val &= (1 << $bits) - 1;
            }
        }
        if ($sawPad && 0 !== $bits) {
            return false;
        }

        return $out;
    }

    private const QPRINT_MAXL = 75;

    /** quoted_printable_encode() — php-src ext/standard/quot_print.c. */
    public static function quoted_printable_encode(string $str): string
    {
        $length = self::byteLength($str);
        if (0 === $length) {
            return '';
        }
        $hex = '0123456789ABCDEF';
        $out = '';
        $lp = 0;
        for ($i = 0; $i < $length; ++$i) {
            $c = self::byteOrd($str[$i]);
            if (13 === $c && $i + 1 < $length && 10 === self::byteOrd($str[$i + 1])) {
                $out .= "\r\n";
                ++$i;
                $lp = 0;

                continue;
            }
            $nextIsCr = ($i + 1 < $length) && 13 === self::byteOrd($str[$i + 1]);
            if (
                $c < 32 || 127 === $c || 0 !== ($c & 0x80) || 61 === $c
                || (32 === $c && $nextIsCr)
            ) {
                if (
                    (($lp += 3) > self::QPRINT_MAXL && $c <= 0x7f)
                    || ($c > 0x7f && $c <= 0xdf && ($lp + 3) > self::QPRINT_MAXL)
                    || ($c > 0xdf && $c <= 0xef && ($lp + 6) > self::QPRINT_MAXL)
                    || ($c > 0xef && $c <= 0xf4 && ($lp + 9) > self::QPRINT_MAXL)
                ) {
                    $out .= "=\r\n";
                    $lp = 3;
                }
                $out .= '='.$hex[$c >> 4].$hex[$c & 0xf];
            } else {
                if ((++$lp) > self::QPRINT_MAXL) {
                    $out .= "=\r\n";
                    $lp = 1;
                }
                $out .= $str[$i];
            }
        }

        return $out;
    }

    /** quoted_printable_decode() — php-src ext/standard/quot_print.c PHP_FUNCTION. */
    public static function quoted_printable_decode(string $str): string
    {
        $inLen = self::byteLength($str);
        if (0 === $inLen) {
            return '';
        }
        $out = '';
        $i = 0;
        while ($i < $inLen) {
            $ch = self::byteOrd($str[$i]);
            if (61 === $ch) {
                if (
                    $i + 2 < $inLen
                    && self::isHexDigit($str[$i + 1])
                    && self::isHexDigit($str[$i + 2])
                ) {
                    $out .= \chr((self::hexDigitVal(self::byteOrd($str[$i + 1])) << 4)
                        + self::hexDigitVal(self::byteOrd($str[$i + 2])));
                    $i += 3;

                    continue;
                }
                $k = 1;
                while ($i + $k < $inLen) {
                    $sk = self::byteOrd($str[$i + $k]);
                    if (32 !== $sk && 9 !== $sk) {
                        break;
                    }
                    ++$k;
                }
                if ($i + $k >= $inLen) {
                    $i += $k;

                    continue;
                }
                if (
                    $i + $k + 1 < $inLen
                    && 13 === self::byteOrd($str[$i + $k])
                    && 10 === self::byteOrd($str[$i + $k + 1])
                ) {
                    $i += $k + 2;

                    continue;
                }
                if ($i + $k < $inLen) {
                    $sk = self::byteOrd($str[$i + $k]);
                    if (13 === $sk || 10 === $sk) {
                        $i += $k + 1;

                        continue;
                    }
                }
                $out .= $str[$i];
                ++$i;
            } else {
                $out .= $str[$i];
                ++$i;
            }
        }

        return $out;
    }

    private static function hexDigitVal(int $c): int
    {
        if ($c >= 48 && $c <= 57) {
            return $c - 48;
        }
        if ($c >= 65 && $c <= 70) {
            return $c - 65 + 10;
        }

        return $c - 97 + 10;
    }

    /** Unix-to-Unix encode (php-src ext/standard/uuencode.c — php_uuencode). */
    public static function convert_uuencode(string $src): string
    {
        $srcLen = self::byteLength($src);
        if (0 === $srcLen) {
            return "`\n";
        }
        $len = 45;
        $out = '';
        $i = 0;
        while ($i + 3 <= $srcLen) {
            $ee = $i + $len;
            if ($ee > $srcLen) {
                $ee = $srcLen;
                $len = $ee - $i;
                if (0 !== ($len % 3)) {
                    $ee = $i + (int) (floor($len / 3) * 3);
                }
            }
            $out .= self::uuEnc($len);
            while ($i < $ee) {
                $b0 = self::byteOrd($src[$i]);
                $b1 = self::byteOrd($src[$i + 1]);
                $b2 = self::byteOrd($src[$i + 2]);
                $out .= self::uuEnc($b0 >> 2);
                $out .= self::uuEnc((($b0 << 4) & 060) | (($b1 >> 4) & 017));
                $out .= self::uuEnc((($b1 << 2) & 074) | (($b2 >> 6) & 03));
                $out .= self::uuEnc($b2 & 077);
                $i += 3;
            }
            if (45 === $len) {
                $out .= "\n";
            }
        }
        if ($i < $srcLen) {
            if (45 === $len) {
                $out .= self::uuEnc($srcLen - $i);
                $len = 0;
            }
            $b0 = self::byteOrd($src[$i]);
            $b1 = ($i + 1 < $srcLen) ? self::byteOrd($src[$i + 1]) : 0;
            $b2 = ($i + 2 < $srcLen) ? self::byteOrd($src[$i + 2]) : 0;
            $out .= self::uuEnc($b0 >> 2);
            $out .= self::uuEnc((($b0 << 4) & 060) | (($b1 >> 4) & 017));
            $out .= ($srcLen - $i > 1)
                ? self::uuEnc((($b1 << 2) & 074) | (($b2 >> 6) & 03))
                : self::uuEnc(0);
            $out .= ($srcLen - $i > 2)
                ? self::uuEnc($b2 & 077)
                : self::uuEnc(0);
        }
        if ($len < 45) {
            $out .= "\n";
        }
        $out .= self::uuEnc(0)."\n";

        return $out;
    }

    /**
     * Unix-to-Unix decode (php-src ext/standard/uuencode.c — php_uudecode).
     *
     * @return string|false
     */
    public static function convert_uudecode(string $src) {
        $srcLen = self::byteLength($src);
        if (0 === $srcLen) {
            return false;
        }
        $totalLen = 0;
        $out = '';
        $i = 0;
        while ($i < $srcLen) {
            $len = self::uuDec(self::byteOrd($src[$i]));
            ++$i;
            if (0 === $len) {
                break;
            }
            if ($len > $srcLen) {
                return false;
            }
            $totalLen += $len;
            $ee = $i + (45 === $len ? 60 : (int) floor($len * 1.33));
            if ($ee > $srcLen) {
                return false;
            }
            while ($i < $ee) {
                if ($i + 4 > $srcLen) {
                    return false;
                }
                $out .= \chr(self::uuDec(self::byteOrd($src[$i])) << 2 | self::uuDec(self::byteOrd($src[$i + 1])) >> 4);
                $out .= \chr(self::uuDec(self::byteOrd($src[$i + 1])) << 4 | self::uuDec(self::byteOrd($src[$i + 2])) >> 2);
                $out .= \chr(self::uuDec(self::byteOrd($src[$i + 2])) << 6 | self::uuDec(self::byteOrd($src[$i + 3])));
                $i += 4;
            }
            if ($len < 45) {
                break;
            }
            ++$i;
        }
        $written = self::byteLength($out);
        if ($written < $totalLen) {
            $len = $totalLen;
            if ($len > $written) {
                $out .= \chr(self::uuDec(self::byteOrd($src[$i])) << 2 | self::uuDec(self::byteOrd($src[$i + 1])) >> 4);
                if ($len > 1) {
                    $out .= \chr(self::uuDec(self::byteOrd($src[$i + 1])) << 4 | self::uuDec(self::byteOrd($src[$i + 2])) >> 2);
                    if ($len > 2) {
                        $out .= \chr(self::uuDec(self::byteOrd($src[$i + 2])) << 6 | self::uuDec(self::byteOrd($src[$i + 3])));
                    }
                }
            }
        }
        if (self::byteLength($out) !== $totalLen) {
            return self::byteSlice($out, 0, $totalLen);
        }

        return $out;
    }

    /** ISO-8859-1 to UTF-8 (php-src ext/standard/basic_functions.c — PHP_FUNCTION(utf8_encode)). */
    public static function utf8_encode(string $data): string
    {
        $srcLen = self::byteLength($data);
        if (0 === $srcLen) {
            return '';
        }
        $out = '';
        for ($i = 0; $i < $srcLen; ++$i) {
            $c = self::byteOrd($data[$i]);
            if ($c < 0x80) {
                $out .= $data[$i];
            } else {
                $out .= \chr(0xC0 | ($c >> 6));
                $out .= \chr(0x80 | ($c & 0x3F));
            }
        }

        return $out;
    }

    /** UTF-8 to ISO-8859-1 (php-src ext/standard/basic_functions.c — PHP_FUNCTION(utf8_decode)). */
    public static function utf8_decode(string $data): string
    {
        $srcLen = self::byteLength($data);
        if (0 === $srcLen) {
            return '';
        }
        $out = '';
        for ($i = 0; $i < $srcLen; ) {
            $c = self::byteOrd($data[$i]);
            if ($c < 0x80) {
                $out .= $data[$i];
                ++$i;
                continue;
            }
            if (($c & 0xE0) === 0xC0) {
                if ($c < 0xC2 || $i + 1 >= $srcLen || (self::byteOrd($data[$i + 1]) & 0xC0) !== 0x80) {
                    $out .= '?';
                    ++$i;
                    continue;
                }
                $cp = (($c & 0x1F) << 6) | (self::byteOrd($data[$i + 1]) & 0x3F);
                $out .= \chr($cp <= 0xFF ? $cp : 0x3F);
                $i += 2;
                continue;
            }
            if (($c & 0xF0) === 0xE0) {
                if ($i + 2 >= $srcLen
                    || (self::byteOrd($data[$i + 1]) & 0xC0) !== 0x80
                    || (self::byteOrd($data[$i + 2]) & 0xC0) !== 0x80) {
                    $out .= '?';
                    ++$i;
                    continue;
                }
                $cp = (($c & 0x0F) << 12)
                    | ((self::byteOrd($data[$i + 1]) & 0x3F) << 6)
                    | (self::byteOrd($data[$i + 2]) & 0x3F);
                $out .= \chr($cp >= 0x800 && $cp <= 0xFF ? $cp : 0x3F);
                $i += 3;
                continue;
            }
            if (($c & 0xF8) === 0xF0) {
                if ($i + 3 >= $srcLen
                    || (self::byteOrd($data[$i + 1]) & 0xC0) !== 0x80
                    || (self::byteOrd($data[$i + 2]) & 0xC0) !== 0x80
                    || (self::byteOrd($data[$i + 3]) & 0xC0) !== 0x80) {
                    $out .= '?';
                    ++$i;
                    continue;
                }
                $out .= '?';
                $i += 4;
                continue;
            }
            $out .= '?';
            ++$i;
        }

        return $out;
    }

    private static function uuEnc(int $c): string
    {
        if (0 === $c) {
            return '`';
        }

        return \chr(($c & 077) + 32);
    }

    private static function uuDec(int $c): int
    {
        return ($c - 32) & 077;
    }

    /** application/x-www-form-urlencoded (space as '+'). */
    public static function urlencode(string $data): string
    {
        return self::percentEncode($data, true);
    }

    /** RFC 3986 raw encoding (space as %20). */
    public static function rawurlencode(string $data): string
    {
        return self::percentEncode($data, false);
    }

    /** application/x-www-form-urlencoded decode ('+' as space). */
    public static function urldecode(string $data): string
    {
        return self::percentDecode($data, true);
    }

    /** RFC 3986 percent-decode (does not map '+' to space). */
    public static function rawurldecode(string $data): string
    {
        return self::percentDecode($data, false);
    }

    /**
     * Cryptographically secure pseudo-random bytes (read from /dev/urandom).
     *
     * @throws \ValueError when length is less than 1
     * @throws \Exception when the operating system cannot supply random data
     */
    /**
     * uniqid() subset: gettimeofday-based id + optional 8 hex entropy chars (#2219).
     */
    public static function uniqid(string $prefix = '', bool $moreEntropy = false): string
    {
        $tv = \gettimeofday();
        $usec = $tv['usec'] % 0x100000;
        $core = \sprintf('%08x%05x', $tv['sec'], $usec);
        if ($moreEntropy) {
            try {
                $rnd = self::randomBytes(4);
                $dec = \unpack('N', $rnd)[1] % 100000000;
            } catch (\Throwable $e) {
                $dec = ($tv['usec'] ^ $tv['sec']) % 100000000;
            }
            $core .= \sprintf('.%08u', $dec);
        }

        return $prefix.$core;
    }

    public static function randomBytes(int $length): string
    {
        if ($length < 1) {
            throw new \ValueError('random_bytes(): Argument #1 ($length) must be greater than 0');
        }
        $fp = @\fopen('/dev/urandom', 'rb');
        if (false === $fp) {
            throw new \Exception('Could not gather sufficient random data');
        }
        $buf = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = \fread($fp, $remaining);
            if (false === $chunk || '' === $chunk) {
                \fclose($fp);
                throw new \Exception('Could not gather sufficient random data');
            }
            $buf .= $chunk;
            $remaining -= self::byteLength($chunk);
        }
        \fclose($fp);

        return $buf;
    }

    /**
     * Minimal parse_url() for routing (http/https, path, query, host).
     *
     * @return array|string|null
     */
    public static function parseUrl(string $url, int $component = -1)
    {
        $scheme = null;
        $host = null;
        $port = null;
        $user = null;
        $pass = null;
        $path = '';
        $query = null;
        $fragment = null;
        $rest = $url;

        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $rest, $m)) {
            $scheme = strtolower($m[1]);
            $rest = substr($rest, strlen($m[0]));
            if (str_starts_with($rest, '//')) {
                $rest = substr($rest, 2);
                $slash = strpos($rest, '/');
                $q = strpos($rest, '?');
                $hash = strpos($rest, '#');
                $end = self::minPositive([$slash, $q, $hash]);
                $authority = false === $end ? $rest : substr($rest, 0, $end);
                $rest = false === $end ? '' : substr($rest, $end);
                if (str_contains($authority, '@')) {
                    $atPos = strrpos($authority, '@');
                    $userinfo = substr($authority, 0, $atPos);
                    $authority = substr($authority, $atPos + 1);
                    if ('' !== $userinfo) {
                        $colonPos = strpos($userinfo, ':');
                        if (false !== $colonPos) {
                            $user = substr($userinfo, 0, $colonPos);
                            $pass = substr($userinfo, $colonPos + 1);
                        } else {
                            $user = $userinfo;
                        }
                    }
                }
                if (str_contains($authority, ':')) {
                    [$host, $portStr] = explode(':', $authority, 2);
                    $port = (int) $portStr;
                } else {
                    $host = $authority;
                }
            }
        }

        if (str_contains($rest, '#')) {
            [$rest, $fragment] = explode('#', $rest, 2);
        }
        if (str_contains($rest, '?')) {
            [$path, $query] = explode('?', $rest, 2);
        } else {
            $path = $rest;
        }

        $parts = [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'pass' => $pass,
            'path' => $path,
            'query' => $query,
            'fragment' => $fragment,
        ];

        if (-1 === $component) {
            $filtered = [];
            foreach ($parts as $key => $value) {
                if (null !== $value && '' !== $value) {
                    $filtered[$key] = $value;
                }
            }

            return $filtered;
        }

        switch ($component) {
            case \PHP_URL_SCHEME:
                return $scheme;
            case \PHP_URL_HOST:
                return $host;
            case \PHP_URL_PORT:
                return $port;
            case \PHP_URL_USER:
                return $user;
            case \PHP_URL_PASS:
                return $pass;
            case \PHP_URL_PATH:
                return $path;
            case \PHP_URL_QUERY:
                return $query;
            case \PHP_URL_FRAGMENT:
                return $fragment;
            default:
                throw new \LogicException('parse_url() component not supported in this compiler build');
        }
    }

    private static function percentEncode(string $data, bool $formEncoding): string
    {
        $len = self::byteLength($data);
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $data[$i];
            $ord = self::byteOrd($ch);
            if (
                ($ord >= 48 && $ord <= 57)
                || ($ord >= 65 && $ord <= 90)
                || ($ord >= 97 && $ord <= 122)
                || $ch === '-' || $ch === '_' || $ch === '.' || $ch === '~'
            ) {
                $out .= $ch;
            } elseif ($formEncoding && $ch === ' ') {
                $out .= '+';
            } else {
                $out .= '%' . strtoupper(self::bin2hex($ch));
            }
        }

        return $out;
    }

    private static function percentDecode(string $data, bool $formDecoding): string
    {
        $len = self::byteLength($data);
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $data[$i];
            if ($formDecoding && '+' === $ch) {
                $out .= ' ';
                continue;
            }
            if ('%' === $ch && $i + 2 < $len) {
                $hi = self::hexDigit(self::byteOrd($data[$i + 1]));
                $lo = self::hexDigit(self::byteOrd($data[$i + 2]));
                if (null !== $hi && null !== $lo) {
                    $out .= \chr(($hi << 4) | $lo);
                    $i += 2;
                    continue;
                }
            }
            $out .= $ch;
        }

        return $out;
    }

    private static function hexDigit(int $ord): ?int
    {
        if ($ord >= 48 && $ord <= 57) {
            return $ord - 48;
        }
        if ($ord >= 65 && $ord <= 70) {
            return $ord - 55;
        }
        if ($ord >= 97 && $ord <= 102) {
            return $ord - 87;
        }

        return null;
    }

    /**
     * @param list<int|false> $candidates
     */
    private static function minPositive(array $candidates)
    {
        $min = false;
        foreach ($candidates as $c) {
            if (false === $c) {
                continue;
            }
            if (false === $min || $c < $min) {
                $min = $c;
            }
        }

        return $min;
    }

    /**
     * @return list<string>
     */
    public static function strSplit(string $string, int $length = 1): array
    {
        if ($length < 1) {
            throw new \ValueError('str_split(): Argument #2 ($length) must be greater than 0');
        }
        $len = self::byteLength($string);
        if (0 === $len) {
            return [];
        }
        $parts = [];
        for ($offset = 0; $offset < $len; $offset += $length) {
            $take = $length;
            if ($offset + $take > $len) {
                $take = $len - $offset;
            }
            $parts[] = self::byteSlice($string, $offset, $take);
        }

        return $parts;
    }

    public static function repeat(string $input, int $multiplier): string
    {
        if ($multiplier < 0) {
            throw new \ValueError('str_repeat(): Argument #2 ($times) must be greater than or equal to 0');
        }
        if (0 === $multiplier) {
            return '';
        }
        $inputLen = self::byteLength($input);
        if (0 === $inputLen) {
            return '';
        }
        $out = '';
        for ($i = 0; $i < $multiplier; ++$i) {
            $out .= $input;
        }

        return $out;
    }

    private static function repeatPadString(string $padString, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        $padding = '';
        while (self::byteLength($padding) < $length) {
            $padding .= $padString;
        }

        return self::byteSlice($padding, 0, $length);
    }

    public static function strPad(string $input, int $padLength, string $padString = ' ', int $padType = 1): string
    {
        $inputLen = self::byteLength($input);
        if ($padLength <= 0 || $padLength <= $inputLen) {
            return $input;
        }
        if ('' === $padString) {
            throw new \ValueError('str_pad(): Argument #3 ($pad_string) must be a non-empty string');
        }
        $need = $padLength - $inputLen;
        if (2 === $padType) {
            $leftNeed = intdiv($need, 2);
            $rightNeed = $need - $leftNeed;

            return self::repeatPadString($padString, $leftNeed).$input.self::repeatPadString($padString, $rightNeed);
        }
        $padding = self::repeatPadString($padString, $need);
        if (0 === $padType) {
            return $padding.$input;
        }

        return $input.$padding;
    }

    public static function htmlspecialchars(
        string $string,
        int $flags = ENT_QUOTES | ENT_SUBSTITUTE,
        string $encoding = 'UTF-8',
        bool $doubleEncode = true
    ): string {
        if (!self::isUtf8Encoding($encoding)) {
            return \htmlspecialchars($string, $flags, $encoding, $doubleEncode);
        }
        $quoteBoth = 0 !== ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            switch ($ch) {
                case '&':
                    if (!$doubleEncode) {
                        $entityLen = self::htmlspecialcharsExistingEntityLen($string, $i, $len);
                        if ($entityLen > 0) {
                            $out .= substr($string, $i, $entityLen);
                            $i += $entityLen - 1;
                            break;
                        }
                    }
                    $out .= '&amp;';
                    break;
                case '<':
                    $out .= '&lt;';
                    break;
                case '>':
                    $out .= '&gt;';
                    break;
                case '"':
                    $out .= ($quoteBoth || $quoteDouble) ? '&quot;' : '"';
                    break;
                case "'":
                    $out .= $quoteBoth ? '&#039;' : "'";
                    break;
                default:
                    $out .= $ch;
            }
        }

        return $out;
    }

    /**
     * get_html_translation_table() — character => entity map (ext/standard/html.c, #3637).
     *
     * @return \PHPCompiler\VM\HashTable
     */
    public static function getHtmlTranslationTable(
        int $table = HTML_SPECIALCHARS,
        int $flags = ENT_COMPAT,
        string $encoding = 'UTF-8'
    ): \PHPCompiler\VM\HashTable {
        if ('UTF-8' !== $encoding) {
            throw new \LogicException(
                'get_html_translation_table() only supports UTF-8 in this compiler build'
            );
        }
        $quoteBoth = ENT_QUOTES === ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));

        if (HTML_SPECIALCHARS === $table) {
            $entries = [
                '&' => '&amp;',
                '<' => '&lt;',
                '>' => '&gt;',
            ];
            if ($quoteBoth || $quoteDouble) {
                $entries['"'] = '&quot;';
            }
            if ($quoteBoth) {
                $entries["'"] = '&#039;';
            }
        } else {
            $entries = HtmlEntityTable::entitiesEntQuotes();
            if (!$quoteBoth && !$quoteDouble) {
                unset($entries['"']);
            }
            if (!$quoteBoth) {
                unset($entries["'"]);
            }
        }

        $ht = new \PHPCompiler\VM\HashTable();
        foreach ($entries as $key => $value) {
            $var = new \PHPCompiler\VM\Variable();
            $var->string($value);
            $ht->add($key, $var);
        }

        return $ht;
    }

    /** htmlentities() — same subset as htmlspecialchars(); PHP default flags ENT_COMPAT (#2472). */
    public static function htmlentities(
        string $string,
        int $flags = ENT_COMPAT,
        string $encoding = 'UTF-8',
        bool $doubleEncode = true
    ): string {
        return self::htmlspecialchars($string, $flags, $encoding, $doubleEncode);
    }

    /**
     * htmlspecialchars_decode() — inverse of {@see htmlspecialchars()} for our entity subset.
     */
    public static function htmlspecialchars_decode(
        string $string,
        int $flags = ENT_QUOTES | ENT_SUBSTITUTE
    ): string {
        $quoteBoth = 0 !== ($flags & ENT_QUOTES);
        $out = '';
        $len = self::byteLength($string);
        $i = 0;
        while ($i < $len) {
            if ('&' !== $string[$i]) {
                $out .= $string[$i];
                ++$i;
                continue;
            }
            if (self::entityAt($string, $i, $len, '&amp;', 5)) {
                $out .= '&';
                $i += 5;
            } elseif (self::entityAt($string, $i, $len, '&lt;', 4)) {
                $out .= '<';
                $i += 4;
            } elseif (self::entityAt($string, $i, $len, '&gt;', 4)) {
                $out .= '>';
                $i += 4;
            } elseif ($quoteBoth && self::entityAt($string, $i, $len, '&quot;', 6)) {
                $out .= '"';
                $i += 6;
            } elseif ($quoteBoth && self::entityAt($string, $i, $len, '&#039;', 6)) {
                $out .= "'";
                $i += 6;
            } elseif ($quoteBoth && self::entityAt($string, $i, $len, '&#39;', 5)) {
                $out .= "'";
                $i += 5;
            } else {
                $out .= '&';
                ++$i;
            }
        }

        return $out;
    }

    /** html_entity_decode() — same subset as htmlspecialchars_decode(); default ENT_COMPAT (#2472). */
    public static function html_entity_decode(
        string $string,
        int $flags = ENT_COMPAT
    ): string {
        return self::htmlspecialchars_decode($string, $flags);
    }

    private static function entityAt(string $string, int $pos, int $len, string $entity, int $entityLen): bool
    {
        if ($pos + $entityLen > $len) {
            return false;
        }
        for ($j = 0; $j < $entityLen; ++$j) {
            if ($string[$pos + $j] !== $entity[$j]) {
                return false;
            }
        }

        return true;
    }

    private static function isUtf8Encoding(string $encoding): bool
    {
        return 0 === strcasecmp($encoding, 'UTF-8');
    }

    /**
     * Length of an existing HTML entity at $pos when $double_encode=false (php-src html.c parity).
     */
    private static function htmlspecialcharsExistingEntityLen(string $string, int $pos, int $len): int
    {
        if ($pos >= $len || '&' !== $string[$pos]) {
            return 0;
        }
        foreach ([
            ['&amp;', 5],
            ['&lt;', 4],
            ['&gt;', 4],
            ['&quot;', 6],
            ['&#039;', 6],
            ['&#39;', 5],
        ] as [$entity, $entityLen]) {
            if (self::entityAt($string, $pos, $len, $entity, $entityLen)) {
                return $entityLen;
            }
        }

        return self::htmlspecialcharsNumericEntityLen($string, $pos, $len);
    }

    /** @return int byte length including leading & and trailing ;, or 0 if not a numeric entity */
    private static function htmlspecialcharsNumericEntityLen(string $string, int $pos, int $len): int
    {
        if ($pos + 3 > $len || '&' !== $string[$pos] || '#' !== $string[$pos + 1]) {
            return 0;
        }
        $i = $pos + 2;
        if ($i >= $len) {
            return 0;
        }
        if ('x' === $string[$i] || 'X' === $string[$i]) {
            ++$i;
            if ($i >= $len || !ctype_xdigit($string[$i])) {
                return 0;
            }
            while ($i < $len && ctype_xdigit($string[$i])) {
                ++$i;
            }
        } else {
            if (!ctype_digit($string[$i])) {
                return 0;
            }
            while ($i < $len && ctype_digit($string[$i])) {
                ++$i;
            }
        }
        if ($i >= $len || ';' !== $string[$i]) {
            return 0;
        }

        return $i - $pos + 1;
    }

    /**
     * strip_tags() subset: removes HTML/PHP tags; optional allow-list like "<b><p>".
     * HTML comments and PHP tags remove their inner content; other tags keep inner text.
     */
    public static function stripTags(string $string, ?string $allowedTags = null): string
    {
        $allowed = null === $allowedTags || '' === $allowedTags
            ? []
            : self::parseAllowedTags($allowedTags);
        $out = '';
        $len = self::byteLength($string);
        $i = 0;
        while ($i < $len) {
            $ch = $string[$i];
            if ('<' !== $ch) {
                $out .= $ch;
                ++$i;
                continue;
            }
            if ($i + 3 < $len && '<!--' === self::byteSlice($string, $i, 4)) {
                $end = self::findSubstring($string, '-->', $i + 4);
                if (false !== $end) {
                    $i = $end + 3;
                    continue;
                }
            }
            if ($i + 1 < $len && '<?' === self::byteSlice($string, $i, 2)) {
                $end = self::findSubstring($string, '?>', $i + 2);
                if (false !== $end) {
                    $i = $end + 2;
                    continue;
                }
            }
            $gt = self::findSubstring($string, '>', $i + 1);
            if (false === $gt) {
                $out .= $ch;
                ++$i;
                continue;
            }
            $tagContent = self::byteSlice($string, $i + 1, $gt - $i - 1);
            $tagName = self::extractTagName($tagContent);
            if (null !== $tagName && [] !== $allowed && self::isTagAllowed($tagName, $allowed)) {
                $out .= self::byteSlice($string, $i, $gt - $i + 1);
            }
            $i = $gt + 1;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function parseAllowedTags(string $allowedTags): array
    {
        $tags = [];
        $len = self::byteLength($allowedTags);
        $i = 0;
        while ($i < $len) {
            if ('<' !== $allowedTags[$i]) {
                ++$i;
                continue;
            }
            $gt = self::findSubstring($allowedTags, '>', $i + 1);
            if (false === $gt) {
                break;
            }
            $name = self::extractTagName(self::byteSlice($allowedTags, $i + 1, $gt - $i - 1));
            if (null !== $name && '' !== $name) {
                $tags[] = $name;
            }
            $i = $gt + 1;
        }

        return $tags;
    }

    private static function extractTagName(string $tagContent): ?string
    {
        $len = self::byteLength($tagContent);
        $i = 0;
        while ($i < $len && self::isTagWhitespace($tagContent[$i])) {
            ++$i;
        }
        if ($i < $len && '/' === $tagContent[$i]) {
            ++$i;
        }
        if ($i >= $len) {
            return null;
        }
        $start = $i;
        while ($i < $len) {
            $ch = $tagContent[$i];
            if (self::isTagWhitespace($ch) || '>' === $ch || '/' === $ch) {
                break;
            }
            if (!ctype_alpha($ch) && !ctype_digit($ch)) {
                return null;
            }
            ++$i;
        }
        if ($start === $i) {
            return null;
        }

        return strtolower(self::byteSlice($tagContent, $start, $i - $start));
    }

    /**
     * @param list<string> $allowed
     */
    private static function isTagAllowed(string $tagName, array $allowed): bool
    {
        $tagName = strtolower($tagName);
        foreach ($allowed as $name) {
            if ($tagName === $name) {
                return true;
            }
        }

        return false;
    }

    private static function isTagWhitespace(string $ch): bool
    {
        return str_contains(self::TRIM_DEFAULT, $ch);
    }

    /**
     * @return list<string>
     */
    public static function explode(string $delimiter, string $string, int $limit = \PHP_INT_MAX): array
    {
        if ('' === $delimiter) {
            throw new \LogicException('explode(): Argument #1 ($separator) cannot be empty');
        }
        if ('' === $string) {
            if ($limit >= 0) {
                return [''];
            }

            return [];
        }
        if ($limit > 1) {
            return self::explodePositiveLimit($delimiter, $string, $limit);
        }
        if ($limit < 0) {
            return self::explodeNegativeLimit($delimiter, $string, $limit);
        }

        return [$string];
    }

    /**
     * php-src ext/standard/string.c — php_explode().
     *
     * @return list<string>
     */
    private static function explodePositiveLimit(string $delimiter, string $string, int $limit): array
    {
        $parts = [];
        $offset = 0;
        $delimLen = self::byteLength($delimiter);
        $strLen = self::byteLength($string);
        $pos = self::findSubstring($string, $delimiter, $offset);
        if (false === $pos) {
            return [self::byteSlice($string, $offset)];
        }
        do {
            $parts[] = self::byteSlice($string, $offset, $pos - $offset);
            $offset = $pos + $delimLen;
            $pos = self::findSubstring($string, $delimiter, $offset);
            --$limit;
        } while (false !== $pos && $limit > 1);
        if ($offset <= $strLen) {
            $parts[] = self::byteSlice($string, $offset);
        }

        return $parts;
    }

    /**
     * php-src ext/standard/string.c — php_explode_negative_limit().
     *
     * @return list<string>
     */
    private static function explodeNegativeLimit(string $delimiter, string $string, int $limit): array
    {
        $delimLen = self::byteLength($delimiter);
        $strLen = self::byteLength($string);
        $positions = [0];
        $offset = 0;
        while (true) {
            $pos = self::findSubstring($string, $delimiter, $offset);
            if (false === $pos) {
                break;
            }
            $offset = $pos + $delimLen;
            $positions[] = $offset;
        }
        $found = \count($positions);
        $toReturn = $limit + $found;
        if ($toReturn <= 0) {
            return [];
        }
        $parts = [];
        for ($i = 0; $i < $toReturn; ++$i) {
            $start = $positions[$i];
            $end = ($i + 1 < $found)
                ? $positions[$i + 1] - $delimLen
                : $strLen;
            $parts[] = self::byteSlice($string, $start, $end - $start);
        }

        return $parts;
    }

    /**
     * @param list<string> $parts
     */
    public static function implode(string $glue, array $parts): string
    {
        if ([] === $parts) {
            return '';
        }
        $result = $parts[0];
        $count = count($parts);
        for ($i = 1; $i < $count; ++$i) {
            $result .= $glue.$parts[$i];
        }

        return $result;
    }

    public static function substr(string $string, int $offset, ?int $length = null): string
    {
        return self::byteSlice($string, $offset, $length);
    }

    public static function trim(string $string, string $characterMask = self::TRIM_DEFAULT): string
    {
        $start = 0;
        $len = self::byteLength($string);
        while ($start < $len && self::charInMask($string[$start], $characterMask)) {
            ++$start;
        }
        if ($start === $len) {
            return '';
        }
        $end = $len - 1;
        while ($end >= $start && self::charInMask($string[$end], $characterMask)) {
            --$end;
        }

        return self::byteSlice($string, $start, $end - $start + 1);
    }

    public static function ltrim(string $string, string $characterMask = self::TRIM_DEFAULT): string
    {
        $start = 0;
        $len = self::byteLength($string);
        while ($start < $len && self::charInMask($string[$start], $characterMask)) {
            ++$start;
        }

        return self::byteSlice($string, $start);
    }

    public static function rtrim(string $string, string $characterMask = self::TRIM_DEFAULT): string
    {
        $len = self::byteLength($string);
        if (0 === $len) {
            return '';
        }
        $end = $len - 1;
        while ($end >= 0 && self::charInMask($string[$end], $characterMask)) {
            --$end;
        }

        return self::byteSlice($string, 0, $end + 1);
    }

    public static function asciiLower(string $string): string
    {
        return self::asciiCaseTransform($string, true);
    }

    public static function asciiUpper(string $string): string
    {
        return self::asciiCaseTransform($string, false);
    }

    /** str_rot13() for byte strings — ASCII letters only. */
    public static function strRot13(string $string): string
    {
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            $ord = self::byteOrd($ch);
            if (($ord >= 65 && $ord <= 77) || ($ord >= 97 && $ord <= 109)) {
                $ch = self::byteChr($ord + 13);
            } elseif (($ord >= 78 && $ord <= 90) || ($ord >= 110 && $ord <= 122)) {
                $ch = self::byteChr($ord - 13);
            }
            $out .= $ch;
        }

        return $out;
    }

    /** Whether every byte is ASCII alphanumeric ([0-9A-Za-z]). */
    public static function onlyAsciiAlphanumeric(string $string): bool
    {
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ord = self::byteOrd($string[$i]);
            if (!(($ord >= 48 && $ord <= 57) || ($ord >= 65 && $ord <= 90) || ($ord >= 97 && $ord <= 122))) {
                return false;
            }
        }

        return true;
    }

    /**
     * str_increment() — PHP 8.3 alphanumeric increment (ext/standard/string.c).
     */
    public static function strIncrement(string $string): string
    {
        if ('' === $string) {
            throw new \ValueError('str_increment(): Argument #1 ($string) must not be empty');
        }
        if (!self::onlyAsciiAlphanumeric($string)) {
            throw new \ValueError('str_increment(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters');
        }

        $incremented = $string;
        $len = self::byteLength($incremented);
        $position = $len - 1;
        $carry = false;

        do {
            $c = $incremented[$position];
            if ('z' !== $c && 'Z' !== $c && '9' !== $c) {
                $carry = false;
                $incremented[$position] = self::byteChr(self::byteOrd($c) + 1);
            } else {
                $carry = true;
                if ('9' === $c) {
                    $incremented[$position] = '0';
                } else {
                    $incremented[$position] = self::byteChr(self::byteOrd($c) - 25);
                }
            }
        } while ($carry && $position-- > 0);

        if ($carry) {
            $prefix = '0' === $incremented[0] ? '1' : $incremented[0];

            return $prefix.$incremented;
        }

        return $incremented;
    }

    /**
     * Zend increment_string() for ++ on string operands (issue #3469).
     *
     * @see Zend/zend_operators.c increment_string()
     */
    public static function incrementStringOperator(string $string): string
    {
        if ('' === $string) {
            return '1';
        }

        $incremented = $string;
        $len = self::byteLength($incremented);
        $position = $len - 1;
        $carry = false;
        $last = 0;

        do {
            $c = $incremented[$position];
            $ord = self::byteOrd($c);
            if ($ord >= 97 && $ord <= 122) {
                if ('z' === $c) {
                    $incremented[$position] = 'a';
                    $carry = true;
                } else {
                    $incremented[$position] = self::byteChr($ord + 1);
                    $carry = false;
                    $last = 1;
                }
            } elseif ($ord >= 65 && $ord <= 90) {
                if ('Z' === $c) {
                    $incremented[$position] = 'A';
                    $carry = true;
                } else {
                    $incremented[$position] = self::byteChr($ord + 1);
                    $carry = false;
                    $last = 2;
                }
            } elseif ($ord >= 48 && $ord <= 57) {
                if ('9' === $c) {
                    $incremented[$position] = '0';
                    $carry = true;
                } else {
                    $incremented[$position] = self::byteChr($ord + 1);
                    $carry = false;
                    $last = 3;
                }
            } else {
                if (!$carry) {
                    $incremented[$position] = self::byteChr($ord + 1);
                }
                $carry = false;
            }
        } while ($carry && $position-- > 0);

        if ($carry) {
            $prefix = match ($last) {
                2 => 'A',
                3 => '0' === $incremented[0] ? '1' : $incremented[0],
                default => 'a',
            };

            return $prefix.$incremented;
        }

        return $incremented;
    }

    /**
     * str_decrement() — PHP 8.3 alphanumeric decrement (ext/standard/string.c).
     */
    public static function strDecrement(string $string): string
    {
        if ('' === $string) {
            throw new \ValueError('str_decrement(): Argument #1 ($string) must not be empty');
        }
        if (!self::onlyAsciiAlphanumeric($string)) {
            throw new \ValueError('str_decrement(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters');
        }
        if ('0' === $string[0]) {
            throw new \ValueError('str_decrement(): Argument #1 ($string) "'.$string.'" is out of decrement range');
        }

        $decremented = $string;
        $len = self::byteLength($decremented);
        $position = $len - 1;
        $carry = false;

        do {
            $c = $decremented[$position];
            if ('a' !== $c && 'A' !== $c && '0' !== $c) {
                $carry = false;
                $decremented[$position] = self::byteChr(self::byteOrd($c) - 1);
            } else {
                $carry = true;
                if ('0' === $c) {
                    $decremented[$position] = '9';
                } else {
                    $decremented[$position] = self::byteChr(self::byteOrd($c) + 25);
                }
            }
        } while ($carry && $position-- > 0);

        if ($carry || ('0' === $decremented[0] && $len > 1)) {
            if (1 === $len) {
                throw new \ValueError('str_decrement(): Argument #1 ($string) "'.$string.'" is out of decrement range');
            }

            return substr($decremented, 1);
        }

        return $decremented;
    }

    public static function pregQuote(string $string, ?string $delimiter = null): string
    {
        $delim = null;
        if (null !== $delimiter && '' !== $delimiter) {
            $delim = $delimiter[0];
        }
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if (str_contains(self::PREG_QUOTE_ESCAPE, $ch) || (null !== $delim && $ch === $delim)) {
                $out .= '\\'.$ch;
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    public static function quotemeta(string $string): string
    {
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if (str_contains(self::QUOTEMETA_ESCAPE, $ch)) {
                $out .= '\\'.$ch;
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    public static function addslashes(string $string): string
    {
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if (self::needsAddslashesEscape($ch)) {
                $out .= '\\'.$ch;
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    public static function stripslashes(string $string): string
    {
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ('\\' === $ch && $i + 1 < $len) {
                $next = $string[$i + 1];
                if (self::needsAddslashesEscape($next)) {
                    $out .= $next;
                    ++$i;
                    continue;
                }
            }
            $out .= $ch;
        }

        return $out;
    }

    private static function needsAddslashesEscape(string $ch): bool
    {
        return '\\' === $ch || "'" === $ch || '"' === $ch || "\0" === $ch;
    }

    /**
     * addcslashes() — prefix backslash before chars in charlist (php-src string.c php_addcslashes).
     */
    public static function addcslashes(string $string, string $charlist): string
    {
        $mask = self::buildAddcslashesCharMask($charlist);
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ($mask[self::byteOrd($ch)]) {
                $out .= '\\'.$ch;
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    /**
     * stripcslashes() — unescape C-style sequences (php-src string.c php_stripcslashes).
     */
    public static function stripcslashes(string $string): string
    {
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ('\\' !== $ch) {
                $out .= $ch;
                continue;
            }
            if ($i + 1 >= $len) {
                $out .= '\\';
                break;
            }
            $next = $string[++$i];
            switch ($next) {
                case 'n':
                    $out .= "\n";
                    break;
                case 'r':
                    $out .= "\r";
                    break;
                case 'a':
                    $out .= "\x07";
                    break;
                case 't':
                    $out .= "\t";
                    break;
                case 'v':
                    $out .= "\v";
                    break;
                case 'b':
                    $out .= "\x08";
                    break;
                case 'f':
                    $out .= "\f";
                    break;
                case 'e':
                    $out .= "\x1B";
                    break;
                case 'x':
                    if ($i + 2 < $len && self::isHexDigit($string[$i + 1]) && self::isHexDigit($string[$i + 2])) {
                        $out .= self::byteChr((int) \hexdec($string[$i + 1].$string[$i + 2]));
                        $i += 2;
                    } else {
                        $out .= 'x';
                    }
                    break;
                default:
                    if ($next >= '0' && $next <= '7') {
                        $oct = $next;
                        $digits = 1;
                        while ($digits < 3 && $i + 1 < $len && $string[$i + 1] >= '0' && $string[$i + 1] <= '7') {
                            $oct .= $string[++$i];
                            ++$digits;
                        }
                        $out .= self::byteChr((int) \octdec($oct));
                    } else {
                        $out .= $next;
                    }
                    break;
            }
        }

        return $out;
    }

    /**
     * substr_replace() — replace substring slice (php-src string.c php_substr_replace).
     */
    public static function substr_replace(string $string, string $replace, int $offset, ?int $length = null): string
    {
        $strLen = self::byteLength($string);
        if ($offset < 0) {
            $offset += $strLen;
            if ($offset < 0) {
                $offset = 0;
            }
        } elseif ($offset > $strLen) {
            $offset = $strLen;
        }
        $remain = $strLen - $offset;
        if (null === $length) {
            $length = $remain;
        } elseif ($length < 0) {
            $length += $remain;
            if ($length < 0) {
                $length = 0;
            }
        } elseif ($length > $remain) {
            $length = $remain;
        }

        return self::byteSlice($string, 0, $offset)
            .$replace
            .self::byteSlice($string, $offset + $length);
    }

    /** @return array<int, bool> */
    private static function buildAddcslashesCharMask(string $charlist): array
    {
        $expanded = self::expandAddcslashesCharlist($charlist);
        $mask = array_fill(0, 256, false);
        $len = self::byteLength($expanded);
        for ($i = 0; $i < $len; ++$i) {
            $c = self::byteOrd($expanded[$i]);
            if ($i + 3 < $len
                && '.' === $expanded[$i + 1]
                && '.' === $expanded[$i + 2]
                && self::byteOrd($expanded[$i + 3]) >= $c) {
                for ($ord = $c; $ord <= self::byteOrd($expanded[$i + 3]); ++$ord) {
                    $mask[$ord] = true;
                }
                $i += 3;
            } else {
                $mask[$c] = true;
            }
        }

        return $mask;
    }

    private static function expandAddcslashesCharlist(string $charlist): string
    {
        $out = '';
        $len = self::byteLength($charlist);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $charlist[$i];
            if ('\\' !== $ch || $i + 1 >= $len) {
                $out .= $ch;
                continue;
            }
            $next = $charlist[++$i];
            switch ($next) {
                case 'n':
                    $out .= "\n";
                    break;
                case 'r':
                    $out .= "\r";
                    break;
                case 'a':
                    $out .= "\x07";
                    break;
                case 't':
                    $out .= "\t";
                    break;
                case 'v':
                    $out .= "\v";
                    break;
                case 'b':
                    $out .= "\x08";
                    break;
                case 'f':
                    $out .= "\f";
                    break;
                case 'e':
                    $out .= "\x1B";
                    break;
                case 'x':
                    if ($i + 2 < $len && self::isHexDigit($charlist[$i + 1]) && self::isHexDigit($charlist[$i + 2])) {
                        $out .= self::byteChr((int) \hexdec($charlist[$i + 1].$charlist[$i + 2]));
                        $i += 2;
                    } else {
                        $out .= 'x';
                    }
                    break;
                default:
                    if ($next >= '0' && $next <= '7') {
                        $oct = $next;
                        $digits = 1;
                        while ($digits < 3 && $i + 1 < $len && $charlist[$i + 1] >= '0' && $charlist[$i + 1] <= '7') {
                            $oct .= $charlist[++$i];
                            ++$digits;
                        }
                        $out .= self::byteChr((int) \octdec($oct));
                    } else {
                        $out .= $next;
                    }
                    break;
            }
        }

        return $out;
    }

    private static function isHexDigit(string $ch): bool
    {
        return ($ch >= '0' && $ch <= '9') || ($ch >= 'a' && $ch <= 'f') || ($ch >= 'A' && $ch <= 'F');
    }

    public static function asciiLcfirst(string $string): string
    {
        if ('' === $string) {
            return '';
        }
        $ch = $string[0];
        $ord = self::byteOrd($ch);
        if ($ord >= 65 && $ord <= 90) {
            $ch = self::byteChr($ord + 32);
        }

        return $ch.self::byteSlice($string, 1);
    }

    public static function asciiUcfirst(string $string): string
    {
        if ('' === $string) {
            return '';
        }
        $ch = $string[0];
        $ord = self::byteOrd($ch);
        if ($ord >= 97 && $ord <= 122) {
            $ch = self::byteChr($ord - 32);
        }

        return $ch.self::byteSlice($string, 1);
    }

    /** ucwords() for byte strings — uppercase first letter after default whitespace (TRIM_DEFAULT). */
    public static function asciiUcwords(string $string): string
    {
        return self::asciiUcwordsEx($string, self::TRIM_DEFAULT);
    }

    /**
     * ucwords() with explicit separator mask (ext/standard/string.c php_ucwords_ex parity; ASCII letters).
     */
    public static function asciiUcwordsEx(string $string, string $separators): string
    {
        if ('' === $string) {
            return '';
        }
        $len = self::byteLength($string);
        $out = '';
        $atWordStart = true;
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ($atWordStart) {
                $ord = self::byteOrd($ch);
                if ($ord >= 97 && $ord <= 122) {
                    $ch = self::byteChr($ord - 32);
                }
            }
            $out .= $ch;
            $atWordStart = str_contains($separators, $ch);
        }

        return $out;
    }

    public static function strReplace(string $search, string $replace, string $subject): string
    {
        if ('' === $search) {
            throw new \LogicException('str_replace(): Argument #1 ($search) cannot be empty');
        }
        $searchLen = self::byteLength($search);
        $out = '';
        $offset = 0;
        $len = self::byteLength($subject);
        while ($offset < $len) {
            $pos = self::findSubstring($subject, $search, $offset);
            if (false === $pos) {
                $out .= self::byteSlice($subject, $offset);
                break;
            }
            $out .= self::byteSlice($subject, $offset, $pos - $offset).$replace;
            $offset = $pos + $searchLen;
        }

        return $out;
    }

    /** Case-insensitive str_replace() for two strings (ASCII fold; subset of PHP). */
    public static function strIreplace(string $search, string $replace, string $subject): string
    {
        if ('' === $search) {
            throw new \LogicException('str_ireplace(): Argument #1 ($search) cannot be empty');
        }
        $searchLen = self::byteLength($search);
        $out = '';
        $offset = 0;
        $len = self::byteLength($subject);
        while ($offset < $len) {
            $pos = self::findSubstringCaseInsensitive($subject, $search, $offset);
            if (false === $pos) {
                $out .= self::byteSlice($subject, $offset);
                break;
            }
            $out .= self::byteSlice($subject, $offset, $pos - $offset).$replace;
            $offset = $pos + $searchLen;
        }

        return $out;
    }

    /**
     * strtr() two-string form — byte translation table (subset of PHP).
     */
    public static function strtr(string $string, string $from, string $to): string
    {
        if ('' === $from) {
            return $string;
        }
        $pairLen = min(self::byteLength($from), self::byteLength($to));
        $table = [];
        for ($i = 0; $i < 256; ++$i) {
            $table[$i] = \chr($i);
        }
        for ($i = 0; $i < $pairLen; ++$i) {
            $table[\ord($from[$i])] = $to[$i];
        }
        $len = self::byteLength($string);
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $out .= $table[\ord($string[$i])];
        }

        return $out;
    }

    /**
     * strtr() replace_pairs array form — longest-match substitution.
     *
     * @see php/php-src ext/standard/string.c php_strtr_array()
     *
     * @param array<string, string> $replacePairs
     */
    public static function strtrArray(string $string, array $replacePairs): string
    {
        $slen = self::byteLength($string);
        if (0 === $slen) {
            return '';
        }
        if ([] === $replacePairs) {
            return $string;
        }

        $pairs = [];
        foreach ($replacePairs as $from => $to) {
            if (!\is_string($from)) {
                $from = (string) $from;
            }
            if (!\is_string($to)) {
                $to = (string) $to;
            }
            if ('' === $from) {
                continue;
            }
            if (self::byteLength($from) > $slen) {
                continue;
            }
            $pairs[$from] = $to;
        }

        if ([] === $pairs) {
            return $string;
        }

        if (1 === \count($pairs)) {
            $from = \array_key_first($pairs);
            $to = $pairs[$from];
            if (1 === self::byteLength($from)) {
                return self::strtr($string, $from, self::byteSlice($to, 0, 1));
            }

            return self::strReplace($from, $to, $string);
        }

        return self::strtrArrayLongestMatch($string, $pairs);
    }

    /**
     * @param array<string, string> $pairs
     */
    private static function strtrArrayLongestMatch(string $string, array $pairs): string
    {
        $slen = self::byteLength($string);
        $minlen = $slen + 1;
        $maxlen = 0;
        $firstChars = [];
        $lengths = [];

        foreach ($pairs as $from => $to) {
            $len = self::byteLength($from);
            if ($len < $minlen) {
                $minlen = $len;
            }
            if ($len > $maxlen) {
                $maxlen = $len;
            }
            $firstChars[\ord($from[0])] = true;
            $lengths[$len] = true;
        }

        if ($minlen > $maxlen) {
            return $string;
        }

        $out = '';
        $pos = 0;
        $oldPos = 0;

        while ($pos <= $slen - $minlen) {
            if (isset($firstChars[\ord($string[$pos])])) {
                $tryLen = $maxlen;
                if ($tryLen > $slen - $pos) {
                    $tryLen = $slen - $pos;
                }
                while ($tryLen >= $minlen) {
                    if (isset($lengths[$tryLen])) {
                        $key = self::byteSlice($string, $pos, $tryLen);
                        if (isset($pairs[$key])) {
                            $out .= self::byteSlice($string, $oldPos, $pos - $oldPos);
                            $out .= $pairs[$key];
                            $oldPos = $pos + $tryLen;
                            $pos = $oldPos - 1;
                            break;
                        }
                    }
                    --$tryLen;
                }
            }
            ++$pos;
        }

        if ('' !== $out) {
            $out .= self::byteSlice($string, $oldPos);

            return $out;
        }

        return $string;
    }

    public static function nl2br(string $string, bool $useXhtml = true): string
    {
        $br = $useXhtml ? '<br />' : '<br>';
        $len = self::byteLength($string);
        $replCount = 0;
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ("\r" === $ch) {
                if ($i + 1 < $len && "\n" === $string[$i + 1]) {
                    ++$i;
                }
                ++$replCount;
            } elseif ("\n" === $ch) {
                if ($i + 1 < $len && "\r" === $string[$i + 1]) {
                    ++$i;
                }
                ++$replCount;
            }
        }
        if (0 === $replCount) {
            return $string;
        }

        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ("\r" === $ch || "\n" === $ch) {
                $out .= $br;
                if ($i + 1 < $len && (
                    ("\r" === $ch && "\n" === $string[$i + 1])
                    || ("\n" === $ch && "\r" === $string[$i + 1])
                )) {
                    $out .= $ch;
                    ++$i;
                    $ch = $string[$i];
                }
                $out .= $ch;
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    /**
     * @return int|false
     */
    public static function strpos(string $haystack, string $needle, int $offset = 0)
    {
        if ('' === $needle) {
            throw new \LogicException('strpos(): Argument #2 ($needle) cannot be empty');
        }
        $offset = self::normalizeContainedStringOffset(
            self::byteLength($haystack),
            $offset,
            'strpos'
        );
        $pos = self::findSubstring($haystack, $needle, $offset);

        return false === $pos ? false : $pos;
    }

    /**
     * @return string|false
     */
    public static function strstr(string $haystack, string $needle, bool $beforeNeedle = false)
    {
        if ('' === $needle) {
            throw new \LogicException('strstr(): Argument #2 ($needle) cannot be empty');
        }
        $pos = self::findSubstring($haystack, $needle, 0);
        if (false === $pos) {
            return false;
        }
        if ($beforeNeedle) {
            return self::byteSlice($haystack, 0, $pos);
        }

        return self::byteSlice($haystack, $pos);
    }

    /**
     * @return string|false
     */
    public static function strrchr(string $haystack, string $needle)
    {
        if ('' === $needle) {
            throw new \LogicException('strrchr(): Argument #2 ($needle) cannot be empty');
        }
        $pos = self::strrpos($haystack, $needle[0], 0);
        if (false === $pos) {
            return false;
        }

        return self::byteSlice($haystack, $pos);
    }

    /**
     * @return string|false
     */
    public static function stristr(string $haystack, string $needle, bool $beforeNeedle = false)
    {
        if ('' === $needle) {
            throw new \LogicException('stristr(): Argument #2 ($needle) cannot be empty');
        }
        $pos = self::findSubstringCaseInsensitive($haystack, $needle, 0);
        if (false === $pos) {
            return false;
        }
        if ($beforeNeedle) {
            return self::byteSlice($haystack, 0, $pos);
        }

        return self::byteSlice($haystack, $pos);
    }

    /**
     * str_word_count() — count words or return word list (ASCII subset of PHP; issue #2382).
     *
     * @return int|list<string>|array<int, string>
     */
    public static function str_word_count(string $string, int $format = 0, string $chars = ''): int|array
    {
        if ($format < 0 || $format > 2) {
            throw new \ValueError('str_word_count(): Argument #2 ($format) must be a valid format value');
        }
        $extra = self::strWordCountExtraMask($chars);
        $len = self::byteLength($string);
        $words = [];
        $positions = [];
        $count = 0;
        $inWord = false;
        $wordStart = 0;
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if (self::isStrWordChar($ch, $inWord, $extra)) {
                if (!$inWord) {
                    $wordStart = $i;
                    $inWord = true;
                    ++$count;
                }
            } elseif ($inWord) {
                if (1 === $format || 2 === $format) {
                    $word = self::byteSlice($string, $wordStart, $i - $wordStart);
                    if (1 === $format) {
                        $words[] = $word;
                    } else {
                        $positions[$wordStart] = $word;
                    }
                }
                $inWord = false;
            }
        }
        if ($inWord && (1 === $format || 2 === $format)) {
            $word = self::byteSlice($string, $wordStart);
            if (1 === $format) {
                $words[] = $word;
            } else {
                $positions[$wordStart] = $word;
            }
        }
        if (0 === $format) {
            return $count;
        }
        if (1 === $format) {
            return $words;
        }

        return $positions;
    }

    /**
     * @return array<int, bool>
     */
    private static function strWordCountExtraMask(string $chars): array
    {
        if ('' === $chars) {
            return [];
        }
        $mask = [];
        $clen = self::byteLength($chars);
        for ($i = 0; $i < $clen; ++$i) {
            $mask[self::byteOrd($chars[$i])] = true;
        }

        return $mask;
    }

    /**
     * @param array<int, bool> $extra
     */
    private static function isStrWordLetter(string $ch): bool
    {
        $ord = self::byteOrd($ch);

        return ($ord >= 65 && $ord <= 90) || ($ord >= 97 && $ord <= 122);
    }

    private static function isStrWordChar(string $ch, bool $inWord, array $extra): bool
    {
        $ord = self::byteOrd($ch);
        if (isset($extra[$ord])) {
            return true;
        }
        if (self::isStrWordLetter($ch)) {
            return true;
        }

        return $inWord && (39 === $ord || 45 === $ord);
    }

    /**
     * Count non-overlapping occurrences of $needle in $haystack (byte-safe subset of PHP).
     */
    public static function substr_count(
        string $haystack,
        string $needle,
        int $offset = 0,
        ?int $length = null
    ): int {
        if ('' === $needle) {
            throw new \LogicException('substr_count(): Argument #2 ($needle) cannot be empty');
        }
        $hayLen = self::byteLength($haystack);
        $needleLen = self::byteLength($needle);
        if ($offset < 0) {
            $offset = 0;
        }
        if ($offset >= $hayLen) {
            return 0;
        }
        $end = $hayLen;
        if (null !== $length) {
            if ($length < 0) {
                return 0;
            }
            $end = $offset + $length;
            if ($end > $hayLen) {
                $end = $hayLen;
            }
        }
        $limit = $end - $needleLen;
        if ($limit < $offset) {
            return 0;
        }
        $count = 0;
        $pos = $offset;
        while ($pos <= $limit) {
            $found = self::findSubstring($haystack, $needle, $pos);
            if (false === $found || $found > $limit) {
                break;
            }
            ++$count;
            $pos = $found + $needleLen;
        }

        return $count;
    }

    /**
     * count_chars() — byte-frequency histogram (PHP 8 modes 0–4; ext/standard/string.c).
     *
     * @return array<int, int>|string
     */
    public static function count_chars(string $string, int $mode = 0): array|string
    {
        if ($mode < 0 || $mode > 4) {
            throw new \LogicException('count_chars(): Argument #2 ($mode) must be between 0 and 4 (inclusive)');
        }
        $counts = array_fill(0, 256, 0);
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            ++$counts[self::byteOrd($string[$i])];
        }
        if (3 === $mode || 4 === $mode) {
            $out = '';
            for ($byte = 0; $byte < 256; ++$byte) {
                if ((3 === $mode && $counts[$byte] > 0) || (4 === $mode && 0 === $counts[$byte])) {
                    $out .= self::byteChr($byte);
                }
            }

            return $out;
        }
        $result = [];
        for ($byte = 0; $byte < 256; ++$byte) {
            if (0 === $mode) {
                $result[$byte] = $counts[$byte];
            } elseif (1 === $mode && $counts[$byte] > 0) {
                $result[$byte] = $counts[$byte];
            } elseif (2 === $mode && 0 === $counts[$byte]) {
                $result[$byte] = 0;
            }
        }

        return $result;
    }

    /**
     * @return int|false
     */
    public static function stripos(string $haystack, string $needle, int $offset = 0)
    {
        if ('' === $needle) {
            throw new \LogicException('stripos(): Argument #2 ($needle) cannot be empty');
        }
        $offset = self::normalizeContainedStringOffset(
            self::byteLength($haystack),
            $offset,
            'stripos'
        );
        $pos = self::findSubstringCaseInsensitive($haystack, $needle, $offset);

        return false === $pos ? false : $pos;
    }

    /**
     * @return int|false
     */
    public static function strrpos(string $haystack, string $needle, int $offset = 0)
    {
        if ('' === $needle) {
            throw new \LogicException('strrpos(): Argument #2 ($needle) cannot be empty');
        }
        $hayLen = self::byteLength($haystack);
        $minStart = 0;
        $maxStart = null;
        $suffixEnd = $hayLen + $offset;
        if ($suffixEnd < $hayLen) {
            if ($suffixEnd < 0) {
                throw new \ValueError(sprintf(
                    'strrpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)'
                ));
            }
            $maxStart = $suffixEnd;
        } else {
            $minStart = $offset;
        }
        $pos = self::findRSubstring($haystack, $needle, $minStart, $maxStart);

        return false === $pos ? false : $pos;
    }

    public static function startsWith(string $haystack, string $needle): bool
    {
        $nlen = self::byteLength($needle);
        if (0 === $nlen) {
            return true;
        }
        $hlen = self::byteLength($haystack);
        if ($nlen > $hlen) {
            return false;
        }

        return self::compareBytes($haystack, $needle, $nlen);
    }

    public static function endsWith(string $haystack, string $needle): bool
    {
        $nlen = self::byteLength($needle);
        if (0 === $nlen) {
            return true;
        }
        $hlen = self::byteLength($haystack);
        if ($nlen > $hlen) {
            return false;
        }

        return self::compareBytes($haystack, $needle, $nlen, $hlen - $nlen);
    }

    private static function charInMask(string $ch, string $mask): bool
    {
        $maskLen = self::byteLength($mask);
        for ($i = 0; $i < $maskLen; ++$i) {
            if ($mask[$i] === $ch) {
                return true;
            }
        }

        return false;
    }

    private static function compareBytes(string $haystack, string $needle, int $length, int $hayOffset = 0): bool
    {
        for ($i = 0; $i < $length; ++$i) {
            if ($haystack[$hayOffset + $i] !== $needle[$i]) {
                return false;
            }
        }

        return true;
    }

    private static function compareBytesCaseInsensitive(string $haystack, string $needle, int $length, int $hayOffset = 0): bool
    {
        for ($i = 0; $i < $length; ++$i) {
            if (self::asciiLowerByte($haystack[$hayOffset + $i]) !== self::asciiLowerByte($needle[$i])) {
                return false;
            }
        }

        return true;
    }

    private static function asciiLowerByte(string $byte): string
    {
        $ord = self::byteOrd($byte);
        if ($ord >= 65 && $ord <= 90) {
            return self::byteChr($ord + 32);
        }

        return $byte;
    }

    /**
     * PHP 8+ strpos/stripos offset: negative counts from end; must lie in [-hayLen, hayLen].
     *
     * @see php/php-src ext/standard/string.c php_strpos()
     */
    private static function normalizeContainedStringOffset(
        int $hayLen,
        int $offset,
        string $functionName,
        int $argNum = 3
    ): int {
        if ($offset < 0) {
            $offset += $hayLen;
        }
        if ($offset < 0 || $offset > $hayLen) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($offset) must be contained in argument #1 ($haystack)',
                $functionName,
                $argNum
            ));
        }

        return $offset;
    }

    /**
     * @return int|false
     */
    private static function findSubstring(string $haystack, string $needle, int $offset)
    {
        $hayLen = self::byteLength($haystack);
        $needleLen = self::byteLength($needle);
        if (0 === $needleLen) {
            return false;
        }
        if ($offset >= $hayLen) {
            return false;
        }
        $limit = $hayLen - $needleLen;
        for ($i = $offset; $i <= $limit; ++$i) {
            if (self::compareBytes($haystack, $needle, $needleLen, $i)) {
                return $i;
            }
        }

        return false;
    }

    /**
     * @return int|false
     */
    private static function findSubstringCaseInsensitive(string $haystack, string $needle, int $offset)
    {
        $hayLen = self::byteLength($haystack);
        $needleLen = self::byteLength($needle);
        if (0 === $needleLen) {
            return false;
        }
        if ($offset >= $hayLen) {
            return false;
        }
        $limit = $hayLen - $needleLen;
        for ($i = $offset; $i <= $limit; ++$i) {
            if (self::compareBytesCaseInsensitive($haystack, $needle, $needleLen, $i)) {
                return $i;
            }
        }

        return false;
    }

    /**
     * @return int|false
     */
    private static function findRSubstring(
        string $haystack,
        string $needle,
        int $offset,
        ?int $maxStart = null
    ) {
        $hayLen = self::byteLength($haystack);
        $needleLen = self::byteLength($needle);
        if (0 === $needleLen) {
            return false;
        }
        if ($offset >= $hayLen) {
            return false;
        }
        $limit = $hayLen - $needleLen;
        if (null !== $maxStart && $maxStart < $limit) {
            $limit = $maxStart;
        }
        if ($limit < $offset) {
            return false;
        }
        $last = false;
        for ($i = $offset; $i <= $limit; ++$i) {
            if (self::compareBytes($haystack, $needle, $needleLen, $i)) {
                $last = $i;
            }
        }

        return $last;
    }

    private static function asciiCaseTransform(string $string, bool $toLower): string
    {
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            $ord = self::byteOrd($ch);
            if ($toLower) {
                if ($ord >= 65 && $ord <= 90) {
                    $ch = self::byteChr($ord + 32);
                }
            } elseif ($ord >= 97 && $ord <= 122) {
                $ch = self::byteChr($ord - 32);
            }
            $out .= $ch;
        }

        return $out;
    }

    public static function dirname(string $path): string
    {
        $len = self::byteLength($path);
        if (0 === $len) {
            return '.';
        }
        $end = $len;
        while ($end > 0 && ('/' === $path[$end - 1] || '\\' === $path[$end - 1])) {
            --$end;
        }
        if (0 === $end) {
            return '/' === $path[0] ? '/' : '.';
        }
        $last = -1;
        for ($i = $end - 1; $i >= 0; --$i) {
            if ('/' === $path[$i] || '\\' === $path[$i]) {
                $last = $i;
                break;
            }
        }
        if (-1 === $last) {
            return '.';
        }
        if (0 === $last) {
            return '/' === $path[0] ? '/' : '.';
        }

        return self::byteSlice($path, 0, $last);
    }

    public static function basename(string $path, string $suffix = ''): string
    {
        $len = self::byteLength($path);
        if (0 === $len) {
            return self::stripBasenameSuffix('', $suffix);
        }
        $end = $len;
        while ($end > 0 && ('/' === $path[$end - 1] || '\\' === $path[$end - 1])) {
            --$end;
        }
        if (0 === $end) {
            return self::stripBasenameSuffix('', $suffix);
        }
        for ($i = $end - 1; $i >= 0; --$i) {
            if ('/' === $path[$i] || '\\' === $path[$i]) {
                return self::stripBasenameSuffix(
                    self::byteSlice($path, $i + 1, $end - $i - 1),
                    $suffix
                );
            }
        }

        return self::stripBasenameSuffix(self::byteSlice($path, 0, $end), $suffix);
    }

    private static function stripBasenameSuffix(string $base, string $suffix): string
    {
        $suffixLen = self::byteLength($suffix);
        if ($suffixLen > 0) {
            $baseLen = self::byteLength($base);
            if ($baseLen >= $suffixLen
                && self::compareBytes($base, $suffix, $suffixLen, $baseLen - $suffixLen)) {
                return self::byteSlice($base, 0, $baseLen - $suffixLen);
            }
        }

        return $base;
    }

    /**
     * @return string|false
     */
    public static function realpath(string $path) {
        if ('' === $path) {
            return false;
        }
        $normalized = self::normalizePath($path);
        if (!file_exists($path)) {
            return false;
        }

        return $normalized;
    }

    public static function normalizePath(string $path): string
    {
        $absolute = '' !== $path && ('/' === $path[0] || '\\' === $path[0]);
        $parts = [];
        $len = self::byteLength($path);
        $segment = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $path[$i];
            if ('/' === $ch || '\\' === $ch) {
                if ('' !== $segment) {
                    if ('..' === $segment) {
                        array_pop($parts);
                    } elseif ('.' !== $segment) {
                        $parts[] = $segment;
                    }
                    $segment = '';
                }

                continue;
            }
            $segment .= $ch;
        }
        if ('' !== $segment) {
            if ('..' === $segment) {
                array_pop($parts);
            } elseif ('.' !== $segment) {
                $parts[] = $segment;
            }
        }
        $joined = implode('/', $parts);
        if ($absolute) {
            return '' === $joined ? '/' : '/'.$joined;
        }

        return '' === $joined ? '.' : $joined;
    }

    /**
     * @return array|string
     */
    public static function pathinfo(string $path, int $flags = 15)
    {
        $dirname = self::dirname($path);
        $basename = self::basename($path);
        $extension = self::pathExtension($path);
        $filename = self::pathFilename($path);

        $mask = $flags & 15;
        if (0 === $mask) {
            return [];
        }

        $parts = [];
        if ($mask & 1) {
            $parts['dirname'] = $dirname;
        }
        if ($mask & 2) {
            $parts['basename'] = $basename;
        }
        if ($mask & 4) {
            $parts['extension'] = $extension;
        }
        if ($mask & 8) {
            $parts['filename'] = $filename;
        }

        if (1 === \count($parts)) {
            return reset($parts);
        }

        // php-src php_pathinfo(): multiple bits (not PATHINFO_ALL) → single string by priority.
        if (15 !== $mask) {
            if ($mask & 1) {
                return $dirname;
            }
            if ($mask & 2) {
                return $basename;
            }
            if ($mask & 4) {
                return $extension;
            }

            return $filename;
        }

        return $parts;
    }

    public static function pathExtension(string $path): string
    {
        $base = self::basename($path);
        $len = self::byteLength($base);
        if (0 === $len) {
            return '';
        }
        $start = '.' === $base[0] ? 1 : 0;
        $lastDot = -1;
        for ($i = $len - 1; $i >= $start; --$i) {
            if ('.' === $base[$i]) {
                $lastDot = $i;
                break;
            }
        }
        if (-1 === $lastDot || $lastDot >= $len - 1) {
            return '';
        }

        return self::byteSlice($base, $lastDot + 1, $len - $lastDot - 1);
    }

    public static function pathFilename(string $path): string
    {
        $base = self::basename($path);
        $ext = self::pathExtension($path);
        if ('' === $ext) {
            return $base;
        }
        $extLen = self::byteLength($ext);
        $baseLen = self::byteLength($base);
        if ($baseLen <= $extLen + 1) {
            return '';
        }

        return self::byteSlice($base, 0, $baseLen - $extLen - 1);
    }

    /** Source string for strtok() continuation (ext/standard/string.c; issue #3201). */
    private static ?string $strtokString = null;

    private static int $strtokLast = 0;

    /**
     * strtok() — tokenize with re-entrant static state (php-src ext/standard/string.c).
     *
     * @return string|false
     */
    public static function strtok(string $str, ?string $tok = null): string|false
    {
        if (null !== $tok) {
            self::$strtokString = $str;
            self::$strtokLast = 0;
            $delimiter = $tok;
        } else {
            if (null === self::$strtokString) {
                return false;
            }
            $delimiter = $str;
        }

        $len = self::byteLength(self::$strtokString);
        $p = self::$strtokLast;
        if ($p >= $len) {
            self::strtokReset();

            return false;
        }

        $table = array_fill(0, 256, false);
        $delLen = self::byteLength($delimiter);
        for ($i = 0; $i < $delLen; ++$i) {
            $table[self::byteOrd($delimiter[$i])] = true;
        }

        $skipped = 0;
        while ($p < $len && $table[self::byteOrd(self::$strtokString[$p])]) {
            ++$p;
            ++$skipped;
            if ($p >= $len) {
                self::strtokReset();

                return false;
            }
        }

        while (++$p < $len) {
            if ($table[self::byteOrd(self::$strtokString[$p])]) {
                $token = self::byteSlice(
                    self::$strtokString,
                    self::$strtokLast + $skipped,
                    $p - self::$strtokLast - $skipped
                );
                self::$strtokLast = $p + 1;

                return $token;
            }
        }

        if ($p > self::$strtokLast) {
            $token = self::byteSlice(
                self::$strtokString,
                self::$strtokLast + $skipped,
                $p - self::$strtokLast - $skipped
            );
            self::strtokReset();

            return $token;
        }

        self::strtokReset();

        return false;
    }

    private static function strtokReset(): void
    {
        self::$strtokString = null;
        self::$strtokLast = 0;
    }

    private static function byteOrd(string $byte): int
    {
        return ord($byte);
    }

    private static function byteChr(int $code): string
    {
        return chr($code);
    }
}
