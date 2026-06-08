<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmGetcwdNative;
use PHPCompiler\VM\Variable;

/**
 * VM helpers for posix builtins (php-src ext/posix/posix.c; #7271, #7376, #7177).
 *
 * Libc via FFI when available; no host \\posix_* delegation (M5 bootstrap path).
 */
final class VmPosix
{
    private static ?\FFI $ffi = null;

    /** Last errno recorded by posix builtins (php-src posix_errno global). */
    private static int $lastError = 0;

    public static function getpid(): int
    {
        return VmDate::getmypid();
    }

    public static function getppid(): int
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            return (int) $ffi->getppid();
        }

        throw new \Error('posix_getppid() is not available in this compiler build');
    }

    public static function getegid(): int
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            return (int) $ffi->getegid();
        }

        throw new \Error('posix_getegid() is not available in this compiler build');
    }

    public static function strerror(int $errno): string
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $msgPtr = $ffi->strerror($errno);
            if (null !== $msgPtr) {
                $msg = \FFI::string($msgPtr);
                if ('' !== $msg) {
                    return $msg;
                }
            }
        }

        return 'Unknown error '.$errno;
    }

    public static function access(string $path, int $mode): bool
    {
        self::$lastError = 0;
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_access() is not available in this compiler build');
        }
        if (0 !== (int) $ffi->access($path, $mode)) {
            self::$lastError = self::readErrno($ffi);

            return false;
        }

        return true;
    }

    public static function mknod(string $path, int $mode, int $major = 0, int $minor = 0): bool
    {
        self::$lastError = 0;
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_mknod() is not available in this compiler build');
        }
        $dev = self::makeDev($mode, $major, $minor);
        if (0 !== (int) $ffi->mknod($path, $mode, $dev)) {
            self::$lastError = self::readErrno($ffi);

            return false;
        }

        return true;
    }

    public static function setuid(int $uid): bool
    {
        return self::setId('setuid', $uid);
    }

    public static function setgid(int $gid): bool
    {
        return self::setId('setgid', $gid);
    }

    public static function seteuid(int $uid): bool
    {
        return self::setId('seteuid', $uid);
    }

    public static function setegid(int $gid): bool
    {
        return self::setId('setegid', $gid);
    }

    /**
     * @throws \TypeError php-src Z_PARAM_LONG rejects enum cases (#7372, #7373, #7374)
     */
    public static function rejectEnumCaseIntArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        $var = $var->resolveIndirect();
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return;
        }
        $given = EnumCaseSupport::typeNameForVariable($var);
        throw new \TypeError(
            \sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                $given
            )
        );
    }

    /**
     * @throws \TypeError
     */
    public static function coerceIntArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): int {
        self::rejectEnumCaseIntArg($var, $function, $argIndex, $paramName);

        return $var->resolveIndirect()->toInt();
    }

    /**
     * @return string|false
     */
    public static function getcwd(): string|false
    {
        self::$lastError = 0;
        $cwd = VmGetcwdNative::resolve();
        if (false === $cwd) {
            $ffi = self::ffi();
            if (null !== $ffi) {
                self::$lastError = self::readErrno($ffi);
            }

            return false;
        }

        return $cwd;
    }

    public static function ctermid(): string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return '';
        }
        $ptr = $ffi->ctermid(null);
        if (null === $ptr) {
            return '';
        }

        return \FFI::string($ptr);
    }

    public static function getLastError(): int
    {
        return self::$lastError;
    }

    public static function setLastError(int $errno): void
    {
        self::$lastError = $errno;
    }

    public static function ffiAvailable(): bool
    {
        return null !== self::ffi();
    }

    private static function setId(string $fn, int $id): bool
    {
        self::$lastError = 0;
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \Error('posix_'.$fn.'() is not available in this compiler build');
        }
        if (0 !== (int) $ffi->$fn($id)) {
            self::$lastError = self::readErrno($ffi);

            return false;
        }

        return true;
    }

    private static function readErrno(\FFI $ffi): int
    {
        $loc = $ffi->__errno_location();

        return (int) $loc[0];
    }

    private static function makeDev(int $mode, int $major, int $minor): int
    {
        $type = $mode & PosixConstants::S_IFMT;
        if ($type !== PosixConstants::S_IFCHR && $type !== PosixConstants::S_IFBLK) {
            return 0;
        }

        return (($major & 0x00000fff) << 8)
            | ($minor & 0x000000ff)
            | (($major & 0xfffff000) << 32)
            | (($minor & 0xffffff00) << 12);
    }

    private static function ffi(): ?\FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\class_exists(\FFI::class, false)) {
            return null;
        }

        $cdef = <<<'CDEF'
typedef int pid_t;
typedef unsigned int mode_t;
typedef unsigned long long dev_t;
typedef unsigned int uid_t;
typedef unsigned int gid_t;
pid_t getppid(void);
gid_t getegid(void);
char *strerror(int errnum);
int access(const char *pathname, int mode);
int mknod(const char *pathname, mode_t mode, dev_t dev);
int setuid(uid_t uid);
int setgid(gid_t gid);
int seteuid(uid_t uid);
int setegid(gid_t gid);
int *__errno_location(void);
char *ctermid(char *s);
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
