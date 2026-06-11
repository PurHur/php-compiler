<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc syslog(3) for VM without Zend host builtin delegation (#3676).
 *
 * php-src: ext/standard/syslog.c, main/php_syslog.c
 */
final class VmSyslog
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    private static ?string $device = null;

    private static bool $haveCalledOpenlog = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function openlog(string $ident, int $option, int $facility): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        self::$device = $ident;
        $ffi->openlog($ident, $option, $facility);
        self::$haveCalledOpenlog = true;

        return true;
    }

    public static function closelog(): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $ffi->closelog();
        self::$device = null;
        self::$haveCalledOpenlog = false;

        return true;
    }

    public static function syslog(int $priority, string $message): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        if (!self::$haveCalledOpenlog) {
            self::openlog('php', 0, StdlibConstants::LOG_USER);
        }

        $ffi->syslog($priority, '%s', $message);

        return true;
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
void openlog(const char *ident, int option, int facility);
void closelog(void);
void syslog(int priority, const char *format, ...);
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
