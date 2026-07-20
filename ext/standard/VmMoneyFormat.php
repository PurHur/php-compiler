<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * money_format() via host money_format(3) or libc strfmon(3) (#3693, ext/standard/formatted_print.c).
 */
final class VmMoneyFormat
{
    private static ?\FFI $ffi = null;

    private static ?bool $ffiAvailable = null;

    public static function available(): bool
    {
        if (\function_exists('money_format')) {
            return true;
        }

        return null !== self::ffi();
    }

    /**
     * @return string|false
     */
    public static function format(string $format, float $value)
    {
        if (\function_exists('money_format')) {
            return @\money_format($format, $value);
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $max = 256;
        while ($max <= 65536) {
            $buf = $ffi->new('char['.$max.']');
            $written = $ffi->strfmon($buf, $max, $format, $value);
            if ($written >= 0) {
                return \FFI::string($buf, $written);
            }
            if (-1 === $written) {
                return false;
            }
            $max *= 2;
        }

        return false;
    }

    private static function ffi(): ?\FFI
    {
        if (null !== self::$ffiAvailable) {
            return self::$ffi;
        }
        self::$ffiAvailable = false;
        if (!\extension_loaded('FFI') || !\class_exists(\FFI::class, false)) {
            return null;
        }
        foreach (['libc.so.6', 'libc.so', 'libc.musl-x86_64.so.1'] as $lib) {
            try {
                self::$ffi = \FFI::cdef(
                    'typedef unsigned long size_t; size_t strfmon(char *s, size_t max, const char *format, double value);',
                    $lib
                );
                self::$ffiAvailable = true;

                return self::$ffi;
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
