<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * Documented thin libc ABI for unavoidable posix write syscalls (#12733).
 *
 * VM paths that cannot use procfs read SSOT (mknod, set*id, setrlimit, setsid,
 * setpgid) load a single minimal FFI cdef here — not in {@see VmPosix}.
 *
 * php-src: ext/posix/posix.c
 */
final class PosixLibcThinAbi
{
    private static ?\FFI $ffi = null;

    private static bool $unavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function readErrno(): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return 0;
        }

        $loc = $ffi->__errno_location();

        return (int) $loc[0];
    }

    public static function mknod(string $path, int $mode, int $dev): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->mknod($path, $mode, $dev);
    }

    public static function mkfifo(string $path, int $mode): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->mkfifo($path, $mode);
    }

    public static function setuid(int $uid): int
    {
        return self::setId('setuid', $uid);
    }

    public static function setgid(int $gid): int
    {
        return self::setId('setgid', $gid);
    }

    public static function seteuid(int $uid): int
    {
        return self::setId('seteuid', $uid);
    }

    public static function setegid(int $gid): int
    {
        return self::setId('setegid', $gid);
    }

    public static function setrlimit(int $resource, int $softLimit, int $hardLimit): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        $rlim = $ffi->new('struct rlimit');
        $rlim->rlim_cur = $softLimit;
        $rlim->rlim_max = $hardLimit;

        return (int) $ffi->setrlimit($resource, \FFI::addr($rlim));
    }

    public static function setsid(): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->setsid();
    }

    public static function setpgid(int $pid, int $pgid): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->setpgid($pid, $pgid);
    }

    private static function setId(string $fn, int $id): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->$fn($id);
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$unavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\class_exists(\FFI::class, false)) {
            self::$unavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef int pid_t;
typedef unsigned int mode_t;
typedef unsigned long long dev_t;
typedef unsigned int uid_t;
typedef unsigned int gid_t;
int mknod(const char *pathname, mode_t mode, dev_t dev);
int mkfifo(const char *pathname, mode_t mode);
int setuid(uid_t uid);
int setgid(gid_t gid);
int seteuid(uid_t uid);
int setegid(gid_t gid);
int *__errno_location(void);
typedef unsigned long rlim_t;
struct rlimit {
    rlim_t rlim_cur;
    rlim_t rlim_max;
};
int setrlimit(int resource, const struct rlimit *rlim);
pid_t setsid(void);
int setpgid(pid_t pid, pid_t pgid);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$unavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = \getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== \strtolower($v)) {
            return false;
        }

        return true;
    }
}
