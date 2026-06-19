<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for ob_gzhandler gzip compression (#9091, php-in-PHP).
 *
 * SSOT: {@see VmZlibNative::gzencode} (ext/zlib/zlib.c — php_zlib_encode).
 */
final class ZlibEncodeJitHelper
{
    public static function gzencode(string $data, int $level, int $encoding): string|false
    {
        return VmZlibNative::gzencode($data, $level, $encoding);
    }
}
