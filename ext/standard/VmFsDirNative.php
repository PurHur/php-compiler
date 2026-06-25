<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * mkdir/rmdir/chmod/chown/chgrp via libc FFI; falls back to {@see VmFsDirPure} when unavailable (#8991).
 *
 * Mirrors {@see \PHPCompiler\JIT\Builtin\StringFsDirJit} (__compiler_mkdir/chown/chgrp)
 * and {@see JitRmdir}/{@see JitChmod} (php-src ext/standard/filestat.c).
 */
final class VmFsDirNative
{
    private const PATH_MAX = 4096;

    private const AT_FDCWD = -100;

    private const AT_SYMLINK_NOFOLLOW = 0x100;

    private static ?\FFI $ffi = null;

    public static function available(): bool
    {
        return null !== self::ffi() || VmFsDirPure::available();
    }

    public static function mkdir(string $path, int $mode, bool $recursive): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $mode &= 0777;
        if (!$recursive) {
            return self::mkdirOne($path, $mode);
        }
        $path = rtrim($path, '/');
        if ('' === $path || \strlen($path) >= self::PATH_MAX) {
            return false;
        }
        $len = \strlen($path);
        for ($i = 1; $i < $len; ++$i) {
            if ('/' !== $path[$i]) {
                continue;
            }
            $partial = \substr($path, 0, $i);
            if ('' === $partial) {
                continue;
            }
            if (VmStatPath::isDir($partial)) {
                continue;
            }
            if (!self::mkdirOne($partial, $mode)) {
                return false;
            }
        }

        return self::mkdirOne($path, $mode);
    }

    public static function rmdir(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmFsDirPure::rmdir($path);
        }

        return 0 === (int) $ffi->rmdir($path);
    }

    public static function chmod(string $path, int $permissions): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmFsDirPure::chmod($path, $permissions);
        }

        return 0 === (int) $ffi->chmod($path, $permissions & 07777);
    }

    public static function chown(string $path, int $uid): bool
    {
        return self::chownAt($path, $uid, -1, false);
    }

    public static function lchown(string $path, int $uid): bool
    {
        return self::chownAt($path, $uid, -1, true);
    }

    public static function chgrp(string $path, int $gid): bool
    {
        return self::chownAt($path, -1, $gid, false);
    }

    public static function lchgrp(string $path, int $gid): bool
    {
        return self::chownAt($path, -1, $gid, true);
    }

    private static function mkdirOne(string $path, int $mode): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmFsDirPure::mkdir($path, $mode, false);
        }

        return 0 === (int) $ffi->mkdir($path, $mode);
    }

    private static function chownAt(string $path, int $uid, int $gid, bool $linkOnly): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmFsDirPure::chownAt($path, $uid, $gid, $linkOnly);
        }
        if ($linkOnly) {
            return 0 === (int) $ffi->fchownat(
                self::AT_FDCWD,
                $path,
                $uid,
                $gid,
                self::AT_SYMLINK_NOFOLLOW
            );
        }

        return 0 === (int) $ffi->chown($path, $uid, $gid);
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
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            return null;
        }

        $cdef = <<<'CDEF'
typedef unsigned int mode_t;
typedef unsigned int uid_t;
typedef unsigned int gid_t;

int mkdir(const char *pathname, mode_t mode);
int rmdir(const char *pathname);
int chmod(const char *pathname, mode_t mode);
int chown(const char *pathname, uid_t owner, gid_t group);
int fchownat(int dirfd, const char *pathname, uid_t owner, gid_t group, int flags);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
