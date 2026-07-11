<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

/** Thin libc ABI for pcntl signal registration (php-src ext/pcntl/pcntl.c; issue #6680). */
final class PcntlLibcThinAbi
{
    private static ?\FFI $ffi = null;

    private static bool $unavailable = false;

    /** @var \Closure|null */
    private static $signalCallback = null;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function supportsNativeDispatch(): bool
    {
        return self::available() && \method_exists(\FFI::class, 'callback');
    }

    public static function installHandler(int $signo): bool
    {
        if (!self::supportsNativeDispatch()) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        if (null === self::$signalCallback) {
            self::$signalCallback = static function (int $signo): void {
                VmPcntl::markPending($signo);
            };
        }
        $handler = \FFI::callback('void(int)', self::$signalCallback);
        $ffi->signal($signo, $handler);

        return true;
    }

    public static function restoreDefault(int $signo): bool
    {
        if (!self::supportsNativeDispatch()) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $ffi->signal($signo, $ffi->cast('sighandler_t', 0));

        return true;
    }

    public static function processAvailable(): bool
    {
        return null !== self::ffi();
    }

    public static function fork(): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->fork();
    }

    public static function waitpid(int $pid, int &$status, int $options): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }
        $statusVar = $ffi->new('int');
        $rc = (int) $ffi->waitpid($pid, \FFI::addr($statusVar), $options);
        $status = (int) $statusVar->cdata;

        return $rc;
    }

    public static function wifexited(int $status): bool
    {
        return 0 === ($status & 0x7f);
    }

    public static function wexitstatus(int $status): int
    {
        return ($status >> 8) & 0xff;
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
typedef void (*sighandler_t)(int);
sighandler_t signal(int signum, sighandler_t handler);
pid_t fork(void);
pid_t waitpid(pid_t pid, int *status, int options);
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
