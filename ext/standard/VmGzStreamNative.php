<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM gz* stream I/O via libz FFI — no host ext/zlib gzopen delegation (#6168, #8220).
 *
 * Mirrors {@see \PHPCompiler\JIT\Builtin\GzStreamIoJit} libz externs; php-src ext/zlib/zlib.c.
 */
final class VmGzStreamNative
{
    /** @var array<int, \FFI\CData> handle id => gzFile */
    private static array $gzFiles = [];

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function isNativeHandle(int $handle): bool
    {
        return isset(self::$gzFiles[$handle]);
    }

    /** @return \FFI\CData|null gzFile pointer */
    public static function lookup(int $handle): ?\FFI\CData
    {
        return self::$gzFiles[$handle] ?? null;
    }

    public static function gzopen(string $filename, string $mode): int|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $fp = $ffi->gzopen($filename, $mode);
            if (null === $fp) {
                return false;
            }
            $id = VmFs::adoptGzNativePlaceholder('compress.zlib://'.$filename);
            if (false === $id) {
                $ffi->gzclose($fp);

                return false;
            }
            self::$gzFiles[$id] = $fp;

            return $id;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function gzwrite(int $handle, string $data, ?int $length = null): int|false
    {
        $fp = self::$gzFiles[$handle] ?? null;
        $ffi = self::ffi();
        if (null === $fp || null === $ffi) {
            return false;
        }
        if (null !== $length) {
            if ($length < 0) {
                return false;
            }
            if ($length < \strlen($data)) {
                $data = \substr($data, 0, $length);
            }
        }
        if ('' === $data) {
            return 0;
        }

        try {
            $buf = self::copyBytes($ffi, $data);
            $written = (int) $ffi->gzwrite($fp, \FFI::addr($buf[0]), \strlen($data));
            if ($written < 0) {
                return false;
            }

            return $written;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function gzread(int $handle, int $length = 8192): string|false
    {
        $fp = self::$gzFiles[$handle] ?? null;
        $ffi = self::ffi();
        if (null === $fp || null === $ffi) {
            return false;
        }
        if ($length < 0) {
            return false;
        }
        if (0 === $length) {
            return '';
        }

        try {
            $buf = $ffi->new('unsigned char['.$length.']');
            $got = (int) $ffi->gzread($fp, \FFI::addr($buf[0]), $length);
            if ($got < 0) {
                return false;
            }
            if (0 === $got) {
                return '';
            }

            return \FFI::string($buf, $got);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function gzclose(int $handle): bool
    {
        $fp = self::$gzFiles[$handle] ?? null;
        unset(self::$gzFiles[$handle]);
        VmFs::releaseGzNativePlaceholder($handle);
        $ffi = self::ffi();
        if (null === $fp || null === $ffi) {
            return false;
        }

        try {
            return 0 === (int) $ffi->gzclose($fp);
        } catch (\Throwable) {
            return false;
        }
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

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef void *gzFile;
gzFile gzopen(const char *path, const char *mode);
int gzwrite(gzFile file, void *buf, unsigned len);
int gzread(gzFile file, void *buf, unsigned len);
int gzclose(gzFile file);
CDEF;

        foreach (['libz.so.1', 'libz.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }
}
