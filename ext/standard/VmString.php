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

    /** Decode a hex string to binary (PHP hex2bin subset; false on invalid input). */
    public static function hex2bin(string $data): string|false
    {
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
    public static function randomBytes(int $length): string
    {
        if ($length < 1) {
            throw new \ValueError('random_bytes(): Argument #1 ($length) must be greater than 0');
        }
        $fp = @\fopen('/dev/urandom', 'rb');
        if (false === $fp) {
            throw new \Exception('Could not gather sufficient random data');
        }
        try {
            $buf = '';
            $remaining = $length;
            while ($remaining > 0) {
                $chunk = \fread($fp, $remaining);
                if (false === $chunk || '' === $chunk) {
                    throw new \Exception('Could not gather sufficient random data');
                }
                $buf .= $chunk;
                $remaining -= self::byteLength($chunk);
            }

            return $buf;
        } finally {
            \fclose($fp);
        }
    }

    /**
     * Minimal parse_url() for routing (http/https, path, query, host).
     *
     * @return array<string, int|string>|string|null
     */
    public static function parseUrl(string $url, int $component = -1)
    {
        $scheme = null;
        $host = null;
        $port = null;
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
                $end = self::minPositive($slash, $q, $hash);
                $authority = false === $end ? $rest : substr($rest, 0, $end);
                $rest = false === $end ? '' : substr($rest, $end);
                if (str_contains($authority, '@')) {
                    $authority = substr($authority, strrpos($authority, '@') + 1);
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
            'path' => $path,
            'query' => $query,
            'fragment' => $fragment,
        ];

        if (-1 === $component) {
            return array_filter(
                $parts,
                static fn ($v) => null !== $v && '' !== $v
            );
        }

        return match ($component) {
            \PHP_URL_SCHEME => $scheme,
            \PHP_URL_HOST => $host,
            \PHP_URL_PORT => $port,
            \PHP_URL_PATH => $path,
            \PHP_URL_QUERY => $query,
            \PHP_URL_FRAGMENT => $fragment,
            default => throw new \LogicException('parse_url() component not supported in this compiler build'),
        };
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
     * @param int|false ...$candidates
     */
    private static function minPositive(...$candidates): int|false
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

    public static function repeat(string $input, int $multiplier): string
    {
        if ($multiplier <= 0) {
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

    public static function strPad(string $input, int $padLength, string $padString = ' ', int $padType = 1): string
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

    /** ucwords() for byte strings — uppercase first letter after default whitespace (TRIM_DEFAULT). */
    public static function asciiUcwords(string $string): string
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
            $atWordStart = self::isTagWhitespace($ch);
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

    /**
     * @return int|false
     */
    public static function stripos(string $haystack, string $needle, int $offset = 0)
    {
        if ('' === $needle) {
            throw new \LogicException('stripos(): Argument #2 ($needle) cannot be empty');
        }
        if ($offset < 0) {
            $offset = 0;
        }
        $pos = self::findSubstringCaseInsensitive($haystack, $needle, $offset);

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

    public static function basename(string $path): string
    {
        $len = self::byteLength($path);
        if (0 === $len) {
            return '';
        }
        $end = $len;
        while ($end > 0 && ('/' === $path[$end - 1] || '\\' === $path[$end - 1])) {
            --$end;
        }
        if (0 === $end) {
            return '';
        }
        for ($i = $end - 1; $i >= 0; --$i) {
            if ('/' === $path[$i] || '\\' === $path[$i]) {
                return self::byteSlice($path, $i + 1, $end - $i - 1);
            }
        }

        return self::byteSlice($path, 0, $end);
    }

    /**
     * @return string|false
     */
    public static function realpath(string $path): string|false
    {
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

    private static function byteOrd(string $byte): int
    {
        return ord($byte);
    }

    private static function byteChr(int $code): string
    {
        return chr($code);
    }
}
