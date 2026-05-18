<?php

declare(strict_types=1);

/**
 * VM-runtime string helpers for the standard library (no PHP userland builtins).
 */

namespace PHPCompiler\ext\standard;

final class VmString
{
    public const TRIM_DEFAULT = " \t\n\r\0\x0B";

    public static function byteLength(string $string): int
    {
        $len = 0;
        while (isset($string[$len])) {
            ++$len;
        }

        return $len;
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

    /**
     * @return list<string>
     */
    public static function strSplit(string $string, int $length = 1): array
    {
        if ($length < 1) {
            throw new \LogicException('str_split(): Argument #2 ($length) must be greater than 0');
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

    public static function strPad(string $input, int $padLength, string $padString = ' ', int $padType = 0): string
    {
        $inputLen = self::byteLength($input);
        if ($padLength <= 0 || $padLength <= $inputLen) {
            return $input;
        }
        if ('' === $padString) {
            throw new \LogicException('str_pad(): Argument #3 ($pad_string) cannot be empty');
        }
        if (2 === $padType) {
            throw new \LogicException('str_pad() STR_PAD_BOTH is not supported in this compiler build');
        }
        $need = $padLength - $inputLen;
        $padding = '';
        while (self::byteLength($padding) < $need) {
            $padding .= $padString;
        }
        $padding = self::byteSlice($padding, 0, $need);
        if (1 === $padType) {
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
        if ('UTF-8' !== $encoding) {
            throw new \LogicException('htmlspecialchars() only supports UTF-8 in this compiler build');
        }
        unset($doubleEncode);
        $quoteBoth = 0 !== ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            switch ($ch) {
                case '&':
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
     * @return list<string>
     */
    public static function explode(string $delimiter, string $string): array
    {
        if ('' === $delimiter) {
            throw new \LogicException('explode(): Argument #1 ($separator) cannot be empty');
        }
        $parts = [];
        $offset = 0;
        $delimLen = self::byteLength($delimiter);
        $strLen = self::byteLength($string);
        while (true) {
            $pos = self::findSubstring($string, $delimiter, $offset);
            if (false === $pos) {
                $parts[] = self::byteSlice($string, $offset);
                break;
            }
            $parts[] = self::byteSlice($string, $offset, $pos - $offset);
            $offset = $pos + $delimLen;
            if ($offset > $strLen) {
                $parts[] = '';
                break;
            }
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

    public static function nl2br(string $string, bool $useXhtml = true): string
    {
        $br = $useXhtml ? '<br />' : '<br>';
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ("\n" === $ch) {
                $out .= $br;
            }
            $out .= $ch;
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
        if ($offset < 0) {
            $offset = 0;
        }
        $pos = self::findSubstring($haystack, $needle, $offset);

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

    private static function byteOrd(string $byte): int
    {
        return ord($byte);
    }

    private static function byteChr(int $code): string
    {
        return chr($code);
    }
}
