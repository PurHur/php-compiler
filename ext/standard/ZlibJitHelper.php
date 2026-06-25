<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * gz*() / zlib_encode|decode for compiled JIT/AOT modules (#9879, php-in-PHP).
 *
 * SSOT: {@see VmZlibNative}
 * php-src: ext/zlib/zlib.c — php_zlib_encode / php_zlib_decode
 */
final class ZlibJitHelper
{
    public static function compressArgv(string $data, int $level, int $encoding): ?string
    {
        $result = VmZlibNative::gzcompress($data, $level, $encoding);

        return false === $result ? null : $result;
    }

    public static function uncompressArgv(string $data, int $maxLength): ?string
    {
        $result = VmZlibNative::gzuncompress($data, $maxLength);

        return false === $result ? null : $result;
    }

    public static function deflateArgv(string $data, int $level, int $encoding): ?string
    {
        $result = VmZlibNative::gzdeflate($data, $level, $encoding);

        return false === $result ? null : $result;
    }

    public static function inflateArgv(string $data, int $maxLength): ?string
    {
        $result = VmZlibNative::gzinflate($data, $maxLength);

        return false === $result ? null : $result;
    }

    public static function encodeArgv(string $data, int $level, int $encoding): ?string
    {
        $result = VmZlibNative::gzencode($data, $level, $encoding);

        return false === $result ? null : $result;
    }

    public static function decodeArgv(string $data, int $maxLength): ?string
    {
        $result = VmZlibNative::gzdecode($data, $maxLength);

        return false === $result ? null : $result;
    }

    public static function zlibEncodeArgv(string $data, int $encoding, int $level): ?string
    {
        $result = VmZlibNative::zlib_encode($data, $encoding, $level);

        return false === $result ? null : $result;
    }

    public static function zlibDecodeArgv(string $data, int $maxLength): ?string
    {
        $result = VmZlibNative::zlib_decode($data, $maxLength);

        return false === $result ? null : $result;
    }
}
