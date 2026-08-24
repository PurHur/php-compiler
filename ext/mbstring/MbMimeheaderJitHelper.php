<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_encode_mimeheader() / mb_decode_mimeheader() NestedJIT runtime (#34299 leftover of #6038).
 *
 * SSOT: {@see VmMbstring::encodeMimeheader()} / {@see VmMbstring::decodeMimeheader()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_encode_mimeheader) / mb_decode_mimeheader
 */
final class MbMimeheaderJitHelper
{
    /**
     * @param string $transferEncoding Leading 'B'/'b' → Base64; otherwise Quoted-Printable (php-src).
     */
    public static function encodeArgv(string $str, string $charset, string $transferEncoding): string
    {
        $base64 = true;
        if ('' !== $transferEncoding) {
            $flag = $transferEncoding[0];
            $base64 = 'B' === $flag || 'b' === $flag;
        }

        return VmMbstring::encodeMimeheader($str, $charset, $base64);
    }

    public static function decodeArgv(string $str): string
    {
        return VmMbstring::decodeMimeheader($str);
    }
}
