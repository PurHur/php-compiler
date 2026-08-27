<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_preferred_mime_name() NestedJIT runtime (#34298 leftover of #13100 / #35275).
 *
 * Leaf assert + mime map — NestedJIT of {@see MbstringEncodingRegistry} (large static
 * table) returns a null/false box under thin AOT (#35275). Peer
 * {@see MbEncodingAliasesJitHelper}.
 *
 * Encoding ValueError must come from an int-returning helper — NestedJIT ValueError
 * from string-returning helpers SIGSEGVs under thin AOT
 * (peer {@see MbStrSplitJitHelper::assertEncodingArgv}).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_preferred_mime_name)
 */
final class MbPreferredMimeNameJitHelper
{
    /**
     * Int-returning encoding check — NestedJIT ValueError from string-returning helpers
     * SIGSEGVs under thin AOT.
     */
    public static function assertEncodingArgv(string $encoding): int
    {
        if ('' === self::canon($encoding)) {
            // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
            throw new \ValueError(
                'mb_preferred_mime_name(): Argument #1 ($encoding) must be a valid encoding, "'.$encoding.'" given'
            );
        }

        return 1;
    }

    /**
     * Preferred MIME name — encoding must already be validated via {@see assertEncodingArgv}.
     * Matches the NestedJIT leaf set of {@see MbstringEncodingRegistry} mime labels.
     */
    public static function preferredArgv(string $encoding): string
    {
        $canonical = self::canon($encoding);
        if ('UTF-8' === $canonical) {
            return 'UTF-8';
        }
        if ('ASCII' === $canonical) {
            return 'US-ASCII';
        }
        if ('ISO-8859-1' === $canonical) {
            return 'ISO-8859-1';
        }
        if ('SJIS' === $canonical || 'CP932' === $canonical) {
            return 'Shift_JIS';
        }
        if ('EUC-JP' === $canonical) {
            return 'EUC-JP';
        }
        if ('ISO-2022-JP' === $canonical) {
            return 'ISO-2022-JP';
        }
        if ('8BIT' === $canonical) {
            return '8bit';
        }
        if ('BASE64' === $canonical) {
            return 'BASE64';
        }
        if ('UUENCODE' === $canonical) {
            return 'x-uuencode';
        }
        if ('Quoted-Printable' === $canonical) {
            return 'Quoted-Printable';
        }
        if ('HTML-ENTITIES' === $canonical) {
            return 'HTML-ENTITIES';
        }

        // assertEncodingArgv already rejected unknowns; defensive empty → AOT false box.
        return '';
    }

    private static function canon(string $encoding): string
    {
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
        if (
            'SJIS' === $encoding || 'sjis' === $encoding
            || 'x-sjis' === $encoding || 'SHIFT-JIS' === $encoding
        ) {
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
        if ('UUENCODE' === $encoding || 'uuencode' === $encoding || 'x-uuencode' === $encoding) {
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
}
