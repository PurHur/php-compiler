<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_http_output() NestedJIT canonicalize (#35231 leftover of #13100 / #20014).
 *
 * Returns a small int code (NestedJIT bool/string statics are unreliable). Mutable
 * encoding is stored in an LLVM module global by {@see JitMbHttpOutput}.
 * Includes php-src `pass` alias (#14315).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_http_output)
 */
final class MbHttpOutputJitHelper
{
    public const CODE_UTF8 = 1;

    public const CODE_ASCII = 2;

    public const CODE_ISO88591 = 3;

    public const CODE_SJIS = 4;

    public const CODE_EUCJP = 5;

    public const CODE_8BIT = 6;

    public const CODE_PASS = 7;

    /**
     * @return int encoding code; throws ValueError when invalid
     */
    public static function canonicalizeArgv(string $encoding): int
    {
        $code = self::codeFor($encoding);
        if (0 === $code) {
            // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
            throw new \ValueError(
                'mb_http_output(): Argument #1 ($encoding) must be a valid encoding, "'.$encoding.'" given'
            );
        }

        return $code;
    }

    private static function codeFor(string $encoding): int
    {
        // Hand-rolled (no strtoupper) — NestedJIT of strtoupper+throw misfires module verify.
        if (
            'pass' === $encoding || 'PASS' === $encoding || 'Pass' === $encoding
        ) {
            return self::CODE_PASS;
        }
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
