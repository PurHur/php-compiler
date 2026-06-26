<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stat(2)/lstat(2) for VM — libc FFI when enabled, else {@see VmStatPure} (#7844, #8903).
 *
 * php-src: Zend/zend_stat.c — php_stat / php_lstat array layout
 * JIT/AOT: JitStatArray LLVM lowering (unchanged).
 */
final class VmStatNative
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi() || VmStatPure::available();
    }

    /**
     * @return array<int|string, int>|false
     */
    public static function stat(string $path)
    {
        return self::invoke($path, false);
    }

    /**
     * @return array<int|string, int>|false
     */
    public static function lstat(string $path)
    {
        return self::invoke($path, true);
    }

    /**
     * fstat(2) on an open fd — php_stream_stat for VmPhpFdStream (#10460).
     *
     * @return array<int|string, int>|false
     */
    public static function fstatFd(int $fd)
    {
        if ($fd < 0) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $buf = $ffi->new('struct stat');
            if (0 !== (int) $ffi->fstat($fd, \FFI::addr($buf))) {
                return false;
            }

            return self::toPhpStatArray($buf);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function realpath(string $path): string|false
    {
        if ('' === $path) {
            $path = '.';
        }
        if (str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $resolved = $ffi->realpath($path, null);
            if (null === $resolved) {
                return false;
            }
            $out = \FFI::string($resolved);
            $ffi->free($resolved);

            return '' === $out ? false : $out;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int|string, int>|false
     */
    private static function invoke(string $path, bool $lstat)
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return $lstat ? VmStatPure::lstat($path) : VmStatPure::stat($path);
        }

        try {
            $buf = $ffi->new('struct stat');
            $rc = $lstat
                ? (int) $ffi->lstat($path, \FFI::addr($buf))
                : (int) $ffi->stat($path, \FFI::addr($buf));
            if (0 !== $rc) {
                return false;
            }

            return self::toPhpStatArray($buf);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int|string, int>
     */
    private static function toPhpStatArray(\FFI\CData $buf): array
    {
        return VmStatPure::normalize([
            'dev' => (int) $buf->st_dev,
            'ino' => (int) $buf->st_ino,
            'mode' => (int) $buf->st_mode,
            'nlink' => (int) $buf->st_nlink,
            'uid' => (int) $buf->st_uid,
            'gid' => (int) $buf->st_gid,
            'rdev' => (int) $buf->st_rdev,
            'size' => (int) $buf->st_size,
            'atime' => (int) $buf->st_atime,
            'mtime' => (int) $buf->st_mtime,
            'ctime' => (int) $buf->st_ctime,
            'blksize' => (int) $buf->st_blksize,
            'blocks' => (int) $buf->st_blocks,
        ]);
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
typedef unsigned long dev_t;
typedef unsigned long ino_t;
typedef unsigned long nlink_t;
typedef unsigned int mode_t;
typedef unsigned int uid_t;
typedef unsigned int gid_t;
typedef long off_t;
typedef long blksize_t;
typedef long blkcnt_t;
typedef long time_t;
struct stat {
    dev_t st_dev;
    ino_t st_ino;
    nlink_t st_nlink;
    mode_t st_mode;
    uid_t st_uid;
    gid_t st_gid;
    int __pad0;
    dev_t st_rdev;
    off_t st_size;
    blksize_t st_blksize;
    blkcnt_t st_blocks;
    time_t st_atime;
    long st_atime_nsec;
    time_t st_mtime;
    long st_mtime_nsec;
    time_t st_ctime;
    long st_ctime_nsec;
};
int stat(const char *pathname, struct stat *statbuf);
int lstat(const char *pathname, struct stat *statbuf);
int fstat(int fd, struct stat *statbuf);
char *realpath(const char *path, char *resolved_path);
void free(void *ptr);
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
