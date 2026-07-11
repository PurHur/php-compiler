<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libz raw-deflate reference via host ext-zlib — php-src ext/zlib/zlib.c parity (#14251).
 *
 * Used only from {@see VmZlibCore::rawDeflate} at nest depth 1 so compiled
 * zlib_encode (ZlibJitHelper) does not recurse. Falls back to sdefl when unavailable.
 */
final class VmZlibLibzReference
{
    private static int $rawDeflateNest = 0;

    public static function rawDeflate(string $data, int $level): ?string
    {
        if (!\function_exists('zlib_encode')) {
            return null;
        }
        if (++self::$rawDeflateNest > 1) {
            --self::$rawDeflateNest;

            return null;
        }
        try {
            $norm = $level;
            if ($norm < 0) {
                $norm = 6;
            }
            if ($norm > 9) {
                $norm = 9;
            }
            $out = @\zlib_encode($data, \ZLIB_ENCODING_RAW, $norm);

            return false === $out ? null : $out;
        } finally {
            --self::$rawDeflateNest;
        }
    }
}
