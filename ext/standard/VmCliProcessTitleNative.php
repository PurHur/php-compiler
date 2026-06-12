<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Linux prctl(PR_SET_NAME) for cli_set_process_title() — thin FFI only (#5155).
 *
 * php-src: ext/standard/cli_ops.c — platform hook; PHP owns title storage in {@see VmCli}.
 */
final class VmCliProcessTitleNative
{
    private const PR_SET_NAME = 15;

    /** Linux TASK_COMM_LEN includes terminating NUL (16 bytes total). */
    private const COMM_MAX_BYTES = 15;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function setKernelCommName(string $title): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }

        $comm = \strlen($title) > self::COMM_MAX_BYTES
            ? \substr($title, 0, self::COMM_MAX_BYTES)
            : $title;

        try {
            $ffi->prctl(self::PR_SET_NAME, $comm);
        } catch (\Throwable) {
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
        if (!self::ffiEnabled() || !\extension_loaded('ffi') || 'Linux' !== \PHP_OS_FAMILY) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
int prctl(int option, char *arg);
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
