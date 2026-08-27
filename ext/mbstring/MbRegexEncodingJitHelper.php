<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_regex_encoding() NestedJIT canonicalize (#35284 leftover of #30781).
 *
 * Returns a small int code (NestedJIT bool/string statics are unreliable). Mutable
 * encoding is stored in an LLVM module global by {@see JitMbRegexEncoding}.
 *
 * php-src: ext/mbstring/php_mbregex.c — PHP_FUNCTION(mb_regex_encoding)
 */
final class MbRegexEncodingJitHelper
{
    public const CODE_UTF8 = 1;

    public const CODE_ASCII = 2;

    public const CODE_ISO88591 = 3;

    public const CODE_SJIS = 4;

    public const CODE_EUCJP = 5;

    public const CODE_8BIT = 6;

    /**
     * @return int encoding code; throws ValueError when invalid
     */
    public static function canonicalizeArgv(string $encoding): int
    {
        $code = self::codeFor($encoding);
        if (0 === $code) {
            // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
            throw new \ValueError(
                'mb_regex_encoding(): Argument #1 ($encoding) must be a valid encoding, "'.$encoding.'" given'
            );
        }

        return $code;
    }

    private static function codeFor(string $encoding): int
    {
        // Hand-rolled (no strtoupper) — NestedJIT of strtoupper+throw misfires module verify.
        if (
            'UTF-8' === $encoding || 'utf-8' === $encoding
            || 'UTF8' === $encoding || 'utf8' === $encoding
        ) {
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
            || 'Shift_JIS' === $encoding || 'shift_jis' === $encoding
            || 'SHIFT-JIS' === $encoding
        ) {
            return self::CODE_SJIS;
        }
        if (
            'EUC-JP' === $encoding || 'euc-jp' === $encoding
            || 'EUC_JP' === $encoding || 'eucJP' === $encoding
        ) {
            return self::CODE_EUCJP;
        }
        if (
            '8BIT' === $encoding || '8bit' === $encoding
            || 'BINARY' === $encoding || 'binary' === $encoding
        ) {
            return self::CODE_8BIT;
        }

        return 0;
    }
}
