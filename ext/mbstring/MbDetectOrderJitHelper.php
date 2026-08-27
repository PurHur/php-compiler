<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_detect_order() NestedJIT runtime (#35280 leftover of #13100 / peer #35278).
 *
 * Leaf pack of comma-separated encodings into an i64 — NestedJIT of
 * {@see MbstringEncodingRegistry::parseOrderList} aborts under thin AOT.
 * Mutable order lives in a module global (peer {@see MbInternalEncodingJitHelper}).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_order)
 */
final class MbDetectOrderJitHelper
{
    public const CODE_ASCII = 1;
    public const CODE_UTF8 = 2;
    public const CODE_ISO88591 = 3;
    public const CODE_SJIS = 4;
    public const CODE_EUCJP = 5;
    public const CODE_ISO2022JP = 6;
    public const CODE_8BIT = 7;
    public const CODE_CP932 = 8;

    /** Max encodings stored in the packed i64 (1 count byte + 7 code bytes). */
    public const MAX_ORDER = 7;

    /**
     * Pack order list string → i64 (byte0=count, byte1..=codes).
     * Int-returning ValueError — NestedJIT string-returning throws SIGSEGV under thin AOT.
     */
    public static function packOrderArgv(string $list): int
    {
        $parts = [];
        $start = 0;
        $len = strlen($list);
        for ($i = 0; $i <= $len; ++$i) {
            if ($i === $len || ',' === $list[$i]) {
                $part = trim(substr($list, $start, $i - $start));
                $start = $i + 1;
                if ('' === $part) {
                    continue;
                }
                $code = self::codeFor($part);
                if (0 === $code) {
                    throw new \ValueError(
                        'mb_detect_order(): Argument #1 ($encoding) contains invalid encoding "'.$part.'"'
                    );
                }
                $parts[] = $code;
            }
        }
        if ([] === $parts) {
            throw new \ValueError(
                'mb_detect_order(): Argument #1 ($encoding) must specify at least one encoding'
            );
        }
        if (\count($parts) > self::MAX_ORDER) {
            throw new \ValueError(
                'mb_detect_order(): Argument #1 ($encoding) has too many encodings for this compiler build'
            );
        }

        $packed = \count($parts);
        $shift = 8;
        foreach ($parts as $code) {
            $packed |= ($code << $shift);
            $shift += 8;
        }

        return $packed;
    }

    public static function codeFor(string $encoding): int
    {
        if ('UTF-8' === $encoding || 'utf-8' === $encoding || 'UTF8' === $encoding || 'utf8' === $encoding) {
            return self::CODE_UTF8;
        }
        if (
            'ASCII' === $encoding || 'ascii' === $encoding
            || 'US-ASCII' === $encoding || 'us-ascii' === $encoding
        ) {
            return self::CODE_ASCII;
        }
        if (
            'ISO-8859-1' === $encoding || 'iso-8859-1' === $encoding
            || 'latin1' === $encoding || 'LATIN1' === $encoding
            || 'ISO8859-1' === $encoding || 'ISO88591' === $encoding
        ) {
            return self::CODE_ISO88591;
        }
        if (
            'SJIS' === $encoding || 'sjis' === $encoding
            || 'x-sjis' === $encoding || 'SHIFT-JIS' === $encoding
        ) {
            return self::CODE_SJIS;
        }
        if (
            'EUC-JP' === $encoding || 'euc-jp' === $encoding || 'EUC' === $encoding
            || 'EUC_JP' === $encoding || 'eucJP' === $encoding || 'x-euc-jp' === $encoding
        ) {
            return self::CODE_EUCJP;
        }
        if ('ISO-2022-JP' === $encoding || 'iso-2022-jp' === $encoding) {
            return self::CODE_ISO2022JP;
        }
        if (
            'CP932' === $encoding || 'cp932' === $encoding || 'MS932' === $encoding
            || 'Windows-31J' === $encoding || 'MS_Kanji' === $encoding
        ) {
            return self::CODE_CP932;
        }
        if ('8BIT' === $encoding || '8bit' === $encoding || 'BINARY' === $encoding || 'binary' === $encoding) {
            return self::CODE_8BIT;
        }

        return 0;
    }

    public static function nameForCode(int $code): string
    {
        if (self::CODE_ASCII === $code) {
            return 'ASCII';
        }
        if (self::CODE_UTF8 === $code) {
            return 'UTF-8';
        }
        if (self::CODE_ISO88591 === $code) {
            return 'ISO-8859-1';
        }
        if (self::CODE_SJIS === $code) {
            return 'SJIS';
        }
        if (self::CODE_EUCJP === $code) {
            return 'EUC-JP';
        }
        if (self::CODE_ISO2022JP === $code) {
            return 'ISO-2022-JP';
        }
        if (self::CODE_8BIT === $code) {
            return '8BIT';
        }
        if (self::CODE_CP932 === $code) {
            return 'CP932';
        }

        return '';
    }

    /**
     * Packed i64 → record-separator-joined names for HT rebuild (peer aliases NestedJIT).
     */
    public static function orderJoinedFromPackedArgv(int $packed): string
    {
        $n = $packed & 0xff;
        if ($n <= 0) {
            return '';
        }
        if ($n > self::MAX_ORDER) {
            $n = self::MAX_ORDER;
        }
        $out = '';
        for ($i = 0; $i < $n; ++$i) {
            $code = ($packed >> (8 * ($i + 1))) & 0xff;
            $name = self::nameForCode($code);
            if ('' === $name) {
                continue;
            }
            if ('' !== $out) {
                $out .= "\x1E";
            }
            $out .= $name;
        }

        return $out;
    }

    /**
     * @param list<string> $order
     */
    public static function packOrderList(array $order): int
    {
        $codes = [];
        foreach ($order as $name) {
            $code = self::codeFor($name);
            if (0 === $code) {
                return 0;
            }
            $codes[] = $code;
        }
        if ([] === $codes || \count($codes) > self::MAX_ORDER) {
            return 0;
        }
        $packed = \count($codes);
        $shift = 8;
        foreach ($codes as $code) {
            $packed |= ($code << $shift);
            $shift += 8;
        }

        return $packed;
    }
}
