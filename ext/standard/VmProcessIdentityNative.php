<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Process identity: /proc pure path first, libc FFI fallback (#9017, #7891, #8351).
 *
 * php-src: ext/standard/basic_functions.c — getmyuid, getmygid, get_current_user, getmypid
 * JIT/AOT: ProcessIdentityJit.php via ProcessIdentityJitHelper (replaces libc getpid/getuid LLVM).
 */
final class VmProcessIdentityNative
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function getuid(): ?int
    {
        $pure = VmProcessIdentityPure::getuid();
        if (null !== $pure) {
            return $pure;
        }

        return self::ffiGetuid();
    }

    public static function getgid(): ?int
    {
        $pure = VmProcessIdentityPure::getgid();
        if (null !== $pure) {
            return $pure;
        }

        return self::ffiGetgid();
    }

    public static function geteuid(): ?int
    {
        $pure = VmProcessIdentityPure::geteuid();
        if (null !== $pure) {
            return $pure;
        }

        return self::ffiGeteuid();
    }

    public static function getpid(): ?int
    {
        $pure = VmProcessIdentityPure::getpid();
        if (null !== $pure) {
            return $pure;
        }

        return self::ffiGetpid();
    }

    public static function getpwuidName(int $uid): ?string
    {
        $pure = VmProcessIdentityPure::getpwuidName($uid);
        if (null !== $pure) {
            return $pure;
        }

        return self::ffiGetpwuidName($uid);
    }

    private static function ffiGetuid(): ?int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        try {
            return (int) $ffi->getuid();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function ffiGetgid(): ?int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        try {
            return (int) $ffi->getgid();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function ffiGeteuid(): ?int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        try {
            return (int) $ffi->geteuid();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function ffiGetpid(): ?int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        try {
            return (int) $ffi->getpid();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function ffiGetpwuidName(int $uid): ?string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        try {
            $pw = $ffi->getpwuid($uid);
            if (null === $pw) {
                return null;
            }
            $name = \FFI::string($pw->pw_name);
            if ('' === $name) {
                return null;
            }

            return $name;
        } catch (\Throwable) {
            return null;
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
typedef unsigned int uid_t;
typedef unsigned int gid_t;
struct passwd {
    char *pw_name;
    char *pw_passwd;
    uid_t pw_uid;
    gid_t pw_gid;
    char *pw_gecos;
    char *pw_dir;
    char *pw_shell;
};
typedef int pid_t;
uid_t getuid(void);
gid_t getgid(void);
uid_t geteuid(void);
pid_t getpid(void);
struct passwd *getpwuid(uid_t uid);
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
