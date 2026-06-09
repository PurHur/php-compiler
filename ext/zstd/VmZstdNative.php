<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

/**
 * VM zstd_* via libzstd FFI (php-src ext/zstd/zstd.c; issues #6382, #6387).
 *
 * No host \zstd_compress() delegation — bootstrap/self-host safe (#3053, #1492).
 */
final class VmZstdNative
{
    private const DEFAULT_LEVEL = 3;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function compress(string $data, int $level = self::DEFAULT_LEVEL): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        if ($level < 1 || $level > 22) {
            return false;
        }

        $srcLen = \strlen($data);
        $bound = (int) $ffi->ZSTD_compressBound($srcLen);
        if ($bound <= 0) {
            return false;
        }

        $dst = $ffi->new('unsigned char['.$bound.']');
        $src = self::copyBytes($ffi, $data);
        $written = (int) $ffi->ZSTD_compress(
            $dst,
            $bound,
            $src,
            $srcLen,
            $level
        );
        if (0 !== (int) $ffi->ZSTD_isError($written)) {
            return false;
        }

        return \FFI::string($dst, $written);
    }

    public static function decompress(string $data): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $srcLen = \strlen($data);
        if (0 === $srcLen) {
            return '';
        }

        $src = self::copyBytes($ffi, $data);
        $contentSize = (int) $ffi->ZSTD_getFrameContentSize($src, $srcLen);
        $dstCap = $contentSize > 0 ? $contentSize : \max(64, $srcLen * 4);
        if ($dstCap <= 0) {
            return false;
        }

        $dst = $ffi->new('unsigned char['.$dstCap.']');
        $written = (int) $ffi->ZSTD_decompress(
            $dst,
            $dstCap,
            $src,
            $srcLen
        );
        if (0 !== (int) $ffi->ZSTD_isError($written)) {
            return false;
        }

        return \FFI::string($dst, $written);
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('FFI')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
size_t ZSTD_compress(void* dst, size_t dstCapacity, const void* src, size_t srcSize, int compressionLevel);
size_t ZSTD_compressBound(size_t srcSize);
size_t ZSTD_decompress(void* dst, size_t dstCapacity, const void* src, size_t compressedSize);
unsigned long long ZSTD_getFrameContentSize(const void* src, size_t srcSize);
unsigned ZSTD_isError(size_t code);
CDEF;

        foreach (['libzstd.so.1', 'libzstd.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function copyBytes(\FFI $ffi, string $data): \FFI\CData
    {
        $len = \strlen($data);
        if (0 === $len) {
            return $ffi->new('unsigned char[1]');
        }
        $buf = $ffi->new('unsigned char['.$len.']', false);
        \FFI::memcpy($buf, $data, $len);

        return $buf;
    }
}
