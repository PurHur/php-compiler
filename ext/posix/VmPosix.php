<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\ext\standard\VmDate;

/**
 * VM helpers for posix builtins (php-src ext/posix/posix.c; #7271).
 *
 * Libc via FFI when available; host POSIX helpers for VM-on-Zend parity.
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
        if (\function_exists('posix_getppid')) {
            return (int) \posix_getppid();
        }

        throw new \Error('posix_getppid() is not available in this compiler build');
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
        if (\function_exists('posix_strerror')) {
            $msg = (string) \posix_strerror($errno);
            if ('' !== $msg) {
                return $msg;
            }
        }

        return 'Unknown error '.$errno;
    }

    public static function getLastError(): int
    {
        return self::$lastError;
    }

    public static function setLastError(int $errno): void
    {
        self::$lastError = $errno;
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
pid_t getppid(void);
char *strerror(int errnum);
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
