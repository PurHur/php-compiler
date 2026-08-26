<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_encoding_aliases() NestedJIT runtime (#35216 leftover of #30795).
 *
 * Thin AOT cannot NestedJIT-construct {@see \PHPCompiler\VM\HashTable} (peer
 * {@see MbStrSplitJitHelper} / #34880). Returns a record-separator-joined string;
 * {@see JitMbEncodingRegistry} rebuilds the HT via explode.
 *
 * Encoding ValueError must come from an int-returning helper — NestedJIT
 * ValueError from string-returning helpers SIGSEGVs under thin AOT
 * (peer {@see MbStrSplitJitHelper::assertEncodingArgv}).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_encoding_aliases)
 */
final class MbEncodingAliasesJitHelper
{
    public const JOIN_DELIM = "\x1E";

    /**
     * Int-returning encoding check — NestedJIT ValueError from string-returning helpers
     * SIGSEGVs under thin AOT; peer {@see MbStrSplitJitHelper::assertEncodingArgv}.
     */
    public static function assertEncodingArgv(string $encoding): int
    {
        if ('' === self::canon($encoding)) {
            // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
            throw new \ValueError(
                'mb_encoding_aliases(): Argument #1 ($encoding) must be a valid encoding, "'.$encoding.'" given'
            );
        }

        return 1;
    }

    /**
     * Joined aliases — encoding must already be validated via {@see assertEncodingArgv}.
     * Empty string = no aliases.
     */
    public static function aliasesJoinedArgv(string $encoding): string
    {
        $canonical = self::canon($encoding);
        if ('UTF-8' === $canonical) {
            return 'utf8';
        }
        if ('ASCII' === $canonical) {
            return 'ANSI_X3.4-1968'."\x1E"
                .'iso-ir-6'."\x1E"
                .'ANSI_X3.4-1986'."\x1E"
                .'ISO_646.irv:1991'."\x1E"
                .'US-ASCII'."\x1E"
                .'ISO646-US'."\x1E"
                .'us'."\x1E"
                .'IBM367'."\x1E"
                .'IBM-367'."\x1E"
                .'cp367'."\x1E"
                .'csASCII';
        }
        if ('ISO-8859-1' === $canonical) {
            return 'latin1'."\x1E".'LATIN1'."\x1E".'ISO8859-1'."\x1E".'ISO88591';
        }
        if ('SJIS' === $canonical) {
            return 'x-sjis'."\x1E".'SHIFT-JIS';
        }
        if ('EUC-JP' === $canonical) {
            return 'EUC'."\x1E".'EUC_JP'."\x1E".'eucJP'."\x1E".'x-euc-jp';
        }
        if ('CP932' === $canonical) {
            return 'MS932'."\x1E".'Windows-31J'."\x1E".'MS_Kanji';
        }
        if ('Quoted-Printable' === $canonical) {
            return 'qprint';
        }
        if ('HTML-ENTITIES' === $canonical) {
            return 'HTML'."\x1E".'html';
        }

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
}
