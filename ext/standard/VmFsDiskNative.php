<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc statvfs(2) for VM disk_free_space() / disk_total_space() when FFI enabled (#3758, #8989).
 *
 * When FFI is disabled, delegates to {@see VmFsDiskPure} host bootstrap path.
 *
 * php-src: ext/standard/filestat.c — php_disk_free_space / php_disk_total_space
 * JIT/AOT: {@see StatPathRuntime} / {@see StatFieldsJitHelper} (#9112).
 */
final class VmFsDiskNative
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi() || VmFsDiskPure::available();
    }

    /**
     * @return float|false
     */
    public static function diskFreeSpace(string $path)
    {
        return self::diskSpace($path, true);
    }

    /**
     * @return float|false
     */
    public static function diskTotalSpace(string $path)
    {
        return self::diskSpace($path, false);
    }

    /**
     * @return float|false
     */
    private static function diskSpace(string $path, bool $free)
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return $free ? VmFsDiskPure::diskFreeSpace($path) : VmFsDiskPure::diskTotalSpace($path);
        }

        try {
            $buf = $ffi->new('struct statvfs');
            if (0 !== (int) $ffi->statvfs($path, \FFI::addr($buf))) {
                return false;
            }
            $frsize = (float) $buf->f_frsize;
            $count = (float) ($free ? $buf->f_bavail : $buf->f_blocks);

            return $count * $frsize;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!self::ffiEnabled() || !\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef unsigned long fsblkcnt_t;
typedef unsigned long fsfilcnt_t;
struct statvfs {
    unsigned long f_bsize;
    unsigned long f_frsize;
    fsblkcnt_t f_blocks;
    fsblkcnt_t f_bfree;
    fsblkcnt_t f_bavail;
    fsfilcnt_t f_files;
    fsfilcnt_t f_ffree;
    fsfilcnt_t f_favail;
    unsigned long f_fsid;
    unsigned long f_flag;
    unsigned long f_namemax;
};
int statvfs(const char *path, struct statvfs *buf);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }
}
