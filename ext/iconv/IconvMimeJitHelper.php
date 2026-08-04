<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

/**
 * iconv_mime_decode() for compiled JIT/AOT modules (#27424, php-in-PHP).
 *
 * SSOT: {@see VmIconvMime::mimeDecode()}
 * php-src: ext/iconv/iconv.c — PHP_FUNCTION(iconv_mime_decode)
 */
final class IconvMimeJitHelper
{
    /**
     * @return string|null null when decode fails (JIT ABI uses null __string__*)
     */
    public static function mimeDecodeArgv(string $encoded, int $mode, string $charset): ?string
    {
        $cs = '' === $charset ? null : $charset;
        $result = VmIconvMime::mimeDecode($encoded, $mode, $cs, null);
        if (false === $result) {
            return null;
        }

        return $result;
    }
}
