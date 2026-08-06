<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

/**
 * VM zstd_* — pure PHP via {@see VmZstdCore} (#8869, #6382, #6387).
 *
 * php-src: ext/zstd/zstd.c
 */
final class VmZstdNative
{
    private static ?\FFI $versionFfi = null;

    private static bool $versionFfiUnavailable = false;

    public static function available(): bool
    {
        return VmZstdCore::available();
    }

    /**
     * ZSTD_versionNumber() — pecl zstd.c ZSTD_VERSION_NUMBER (#28079).
     */
    public static function versionNumber(): int
    {
        $ffi = self::versionFfi();
        if (null === $ffi) {
            return 0;
        }
        try {
            return (int) $ffi->ZSTD_versionNumber();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * ZSTD_versionString() — pecl zstd.c ZSTD_VERSION_TEXT (#28079).
     */
    public static function versionText(): string
    {
        $ffi = self::versionFfi();
        if (null === $ffi) {
            return '0.0.0';
        }
        try {
            $ptr = $ffi->ZSTD_versionString();

            return \is_string($ptr) ? $ptr : (string) $ptr;
        } catch (\Throwable) {
            $n = self::versionNumber();
            if ($n <= 0) {
                return '0.0.0';
            }
            $major = intdiv($n, 10000);
            $minor = intdiv($n % 10000, 100);
            $patch = $n % 100;

            return $major.'.'.$minor.'.'.$patch;
        }
    }

    public static function compress(string $data, int $level = 3): string|false
    {
        return VmZstdCore::compress($data, $level);
    }

    public static function decompress(string $data): string|false
    {
        return VmZstdCore::decompress($data);
    }

    private static function versionFfi(): ?\FFI
    {
        if (self::$versionFfiUnavailable) {
            return null;
        }
        if (null !== self::$versionFfi) {
            return self::$versionFfi;
        }
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            self::$versionFfiUnavailable = true;

            return null;
        }
        if (!\extension_loaded('FFI')) {
            self::$versionFfiUnavailable = true;

            return null;
        }
        $cdef = <<<'CDEF'
unsigned ZSTD_versionNumber(void);
const char* ZSTD_versionString(void);
CDEF;
        foreach (['libzstd.so.1', 'libzstd.so'] as $lib) {
            try {
                self::$versionFfi = \FFI::cdef($cdef, $lib);

                return self::$versionFfi;
            } catch (\Throwable) {
            }
        }
        self::$versionFfiUnavailable = true;

        return null;
    }
}
