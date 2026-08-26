<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_encoding_aliases() NestedJIT runtime (#35216 leftover of #30795).
 *
 * Thin AOT cannot NestedJIT-construct {@see \PHPCompiler\VM\HashTable} (peer
 * {@see MbStrSplitJitHelper} / #27660). Returns a record-separator-joined string;
 * {@see JitMbEncodingRegistry} rebuilds the HT via explode.
 *
 * Encoding assert lives in {@see MbEncodingAliasesAssertJitHelper} (separate NestedJIT TU).
 * NestedJIT must not call {@see MbstringEncodingRegistry} / CharsetEngine (SEGV — peer
 * {@see MbConvertEncodingJitHelper}). Hand-rolled resolve + single-literal joins.
 *
 * SSOT (VM / compile-time fold): {@see MbstringEncodingRegistry::aliases()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_encoding_aliases)
 */
final class MbEncodingAliasesJitHelper
{
    public const JOIN_DELIM = "\x1E";

    /** NestedJIT empty-string returns SEGV — sentinel → empty HT in lowering. */
    public const EMPTY_ALIASES = "\x1FEMPTY";

    /**
     * NestedJIT peel — joined aliases for a runtime encoding name.
     * Encoding must already be validated via {@see MbEncodingAliasesAssertJitHelper::assertEncodingArgv}.
     */
    public static function aliasesJoinedArgv(string $encoding): string
    {
        $canonical = self::resolve($encoding);
        if ('' === $canonical) {
            return self::EMPTY_ALIASES;
        }
        if ('UTF-8' === $canonical) {
            return 'utf8';
        }
        if ('ASCII' === $canonical) {
            return self::joinedAscii();
        }
        if ('ISO-8859-1' === $canonical) {
            return self::joinedLatin1();
        }
        if ('SJIS' === $canonical) {
            return self::joinedSjis();
        }
        if ('EUC-JP' === $canonical) {
            return self::joinedEucJp();
        }
        if ('ISO-2022-JP' === $canonical) {
            return self::EMPTY_ALIASES;
        }
        if ('CP932' === $canonical) {
            return self::joinedCp932();
        }
        if ('8BIT' === $canonical) {
            return 'binary';
        }
        if ('BASE64' === $canonical || 'UUENCODE' === $canonical) {
            return self::EMPTY_ALIASES;
        }
        if ('Quoted-Printable' === $canonical) {
            return 'qprint';
        }
        if ('HTML-ENTITIES' === $canonical) {
            return self::joinedHtmlEntities();
        }

        return self::EMPTY_ALIASES;
    }

    private static function joinedAscii(): string
    {
        return "ANSI_X3.4-1968\x1Eiso-ir-6\x1EANSI_X3.4-1986\x1EISO_646.irv:1991\x1EUS-ASCII\x1EISO646-US\x1Eus\x1EIBM367\x1EIBM-367\x1Ecp367\x1EcsASCII";
    }

    private static function joinedLatin1(): string
    {
        return "latin1\x1ELATIN1\x1EISO8859-1\x1EISO88591";
    }

    private static function joinedSjis(): string
    {
        return "x-sjis\x1ESHIFT-JIS";
    }

    private static function joinedEucJp(): string
    {
        return "EUC\x1EEUC_JP\x1EeucJP\x1Ex-euc-jp";
    }

    private static function joinedCp932(): string
    {
        return "MS932\x1EWindows-31J\x1EMS_Kanji";
    }

    private static function joinedHtmlEntities(): string
    {
        return "HTML\x1Ehtml";
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
