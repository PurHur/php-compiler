<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_encoding_aliases() NestedJIT encoding assert (#35216).
 *
 * Kept in a separate TU from {@see MbEncodingAliasesJitHelper} — packing assert +
 * large alias-literal tables into one NestedJIT unit made ValueError paths SEGV.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_encoding_aliases)
 */
final class MbEncodingAliasesAssertJitHelper
{
    /**
     * Int-returning encoding check — NestedJIT ValueError from string-returning helpers
     * SIGSEGVs under thin AOT; int helpers match {@see MbStrSplitJitHelper::assertEncodingArgv}.
     */
    public static function assertEncodingArgv(string $encoding): int
    {
        if ('' === self::resolve($encoding)) {
            throw new \ValueError(
                'mb_encoding_aliases(): Argument #1 ($encoding) must be a valid encoding, "'.$encoding.'" given'
            );
        }

        return 1;
    }

    private static function resolve(string $name): string
    {
        $norm = self::normKey($name);
        if ('' === $norm) {
            return '';
        }
        if ('UTF8' === $norm) {
            return 'UTF-8';
        }
        if (
            'ASCII' === $norm || 'USASCII' === $norm || 'ANSIX3.41968' === $norm
            || 'ISOIR6' === $norm || 'ANSIX3.41986' === $norm || 'ISO646.IRV1991' === $norm
            || 'ISO646US' === $norm || 'US' === $norm || 'IBM367' === $norm
            || 'CP367' === $norm || 'CSASCII' === $norm
        ) {
            return 'ASCII';
        }
        if ('ISO88591' === $norm || 'LATIN1' === $norm) {
            return 'ISO-8859-1';
        }
        if ('SJIS' === $norm || 'XSJIS' === $norm || 'SHIFTJIS' === $norm) {
            return 'SJIS';
        }
        if ('EUCJP' === $norm || 'EUC' === $norm || 'XEUCJP' === $norm) {
            return 'EUC-JP';
        }
        if ('ISO2022JP' === $norm) {
            return 'ISO-2022-JP';
        }
        if (
            'CP932' === $norm || 'MS932' === $norm || 'WINDOWS31J' === $norm
            || 'MSKANJI' === $norm
        ) {
            return 'CP932';
        }
        if ('8BIT' === $norm || 'BINARY' === $norm) {
            return '8BIT';
        }
        if ('BASE64' === $norm) {
            return 'BASE64';
        }
        if ('UUENCODE' === $norm || 'XUUENCODE' === $norm) {
            return 'UUENCODE';
        }
        if ('QUOTEDPRINTABLE' === $norm || 'QPRINT' === $norm) {
            return 'Quoted-Printable';
        }
        if ('HTMLENTITIES' === $norm || 'HTML' === $norm) {
            return 'HTML-ENTITIES';
        }

        return '';
    }

    private static function normKey(string $name): string
    {
        $out = '';
        $i = 0;
        while (isset($name[$i])) {
            $ch = $name[$i];
            if ('-' !== $ch && '_' !== $ch && ' ' !== $ch && ':' !== $ch) {
                $o = \ord($ch);
                if ($o >= 97 && $o <= 122) {
                    $ch = \chr($o - 32);
                }
                $out .= $ch;
            }
            ++$i;
        }

        return $out;
    }
}
