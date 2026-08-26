<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_internal_encoding() NestedJIT runtime (#35221 leftover of #13100/#20014).
 *
 * Owns the internal-encoding string in this helper TU. NestedJIT must not call
 * {@see MbstringState} / {@see MbstringEncodingRegistry} — those abort under thin AOT
 * (peer {@see DefaultTimezoneJitHelper} / {@see MbEncodingAliasesJitHelper}).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_internal_encoding)
 */
final class MbInternalEncodingJitHelper
{
    private static string $internalEncoding = 'UTF-8';

    public static function getArgv(): string
    {
        return self::$internalEncoding;
    }

    /**
     * Int-returning setter — NestedJIT bool helpers are unreliable; peer strlenArgv (#34625).
     *
     * @return int 1 on success (throws ValueError on invalid encoding)
     */
    public static function setArgv(string $encoding): int
    {
        $canon = self::canon($encoding);
        if ('' === $canon) {
            // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
            throw new \ValueError(
                'mb_internal_encoding(): Argument #1 ($encoding) must be a valid encoding, "'.$encoding.'" given'
            );
        }
        // NestedJIT: static←param/temp aliases — UAF on subsequent get (#33950).
        self::$internalEncoding = self::copyEncoding($canon);

        return 1;
    }

    private static function canon(string $encoding): string
    {
        // Hand-rolled (no strtoupper) — NestedJIT of strtoupper+throw misfires module verify.
        if ('UTF-8' === $encoding || 'utf-8' === $encoding || 'UTF8' === $encoding || 'utf8' === $encoding) {
            return 'UTF-8';
        }
        if (
            'ASCII' === $encoding || 'ascii' === $encoding
            || 'US-ASCII' === $encoding || 'us-ascii' === $encoding
        ) {
            return 'ASCII';
        }
        if (
            'ISO-8859-1' === $encoding || 'iso-8859-1' === $encoding
            || 'latin1' === $encoding || 'LATIN1' === $encoding
            || 'ISO8859-1' === $encoding || 'ISO88591' === $encoding
        ) {
            return 'ISO-8859-1';
        }
        if ('SJIS' === $encoding || 'sjis' === $encoding || 'x-sjis' === $encoding || 'SHIFT-JIS' === $encoding) {
            return 'SJIS';
        }
        if (
            'EUC-JP' === $encoding || 'euc-jp' === $encoding || 'EUC' === $encoding
            || 'EUC_JP' === $encoding || 'eucJP' === $encoding || 'x-euc-jp' === $encoding
        ) {
            return 'EUC-JP';
        }
        if ('ISO-2022-JP' === $encoding || 'iso-2022-jp' === $encoding) {
            return 'ISO-2022-JP';
        }
        if (
            'CP932' === $encoding || 'cp932' === $encoding || 'MS932' === $encoding
            || 'Windows-31J' === $encoding || 'MS_Kanji' === $encoding
        ) {
            return 'CP932';
        }
        if ('8BIT' === $encoding || '8bit' === $encoding || 'BINARY' === $encoding || 'binary' === $encoding) {
            return '8BIT';
        }
        if ('BASE64' === $encoding || 'base64' === $encoding) {
            return 'BASE64';
        }
        if ('UUENCODE' === $encoding || 'uuencode' === $encoding) {
            return 'UUENCODE';
        }
        if (
            'Quoted-Printable' === $encoding || 'quoted-printable' === $encoding
            || 'qprint' === $encoding
        ) {
            return 'Quoted-Printable';
        }
        if (
            'HTML-ENTITIES' === $encoding || 'html-entities' === $encoding
            || 'HTML' === $encoding || 'html' === $encoding
        ) {
            return 'HTML-ENTITIES';
        }

        return '';
    }

    /** Durable copy for NestedJIT static storage (#33950). */
    private static function copyEncoding(string $encoding): string
    {
        $copy = '';
        $len = \strlen($encoding);
        $i = 0;
        while ($i < $len) {
            $copy .= $encoding[$i];
            ++$i;
        }

        return $copy;
    }
}
