<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * zlib.deflate / zlib.inflate built-in stream filters (ext/zlib/zlib.c; #14226, #4656).
 *
 * PHP-in-PHP via {@see VmZlibCore}; wired through {@see VmStreamFilterChain}.
 * php-src: php_zlib_deflate_filter / php_zlib_inflate_filter — READ uses raw RFC1951,
 * WRITE deflate uses zlib RFC1950 wrapper.
 */
final class VmZlibStreamFilter
{
    public static function deflate(string $data, int $direction): string
    {
        if ('' === $data) {
            return '';
        }
        $encoding = VmStreamFilterChain::READ === $direction
            ? \ZLIB_ENCODING_RAW
            : \ZLIB_ENCODING_DEFLATE;
        $result = VmZlibCore::gzdeflate($data, -1, $encoding);

        return false === $result ? $data : $result;
    }

    public static function inflate(string $data, int $direction): string
    {
        if ('' === $data) {
            return '';
        }
        $result = VmZlibCore::gzinflate($data);
        if (false !== $result) {
            return $result;
        }
        if (VmStreamFilterChain::WRITE === $direction) {
            return $data;
        }

        return '';
    }
}
