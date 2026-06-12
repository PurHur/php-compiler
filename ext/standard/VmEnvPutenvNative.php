<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc putenv(3)/getenv(3) for VM without host PHP \putenv()/\getenv() (#8086, #5345 phase 2).
 *
 * Mirrors {@see JitEnv} / {@see \PHPCompiler\JIT\Builtin\StringGetenv} — persistent buffers for putenv.
 *
 * php-src: ext/standard/basic_functions.c — zif_putenv, zif_getenv
 */
final class VmEnvPutenvNative
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    /** @var list<\FFI\CData> putenv() retains assignment buffers for process lifetime */
    private static array $putenvBuffers = [];

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function putenv(string $setting): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $buf = self::copyCString($ffi, $setting);
        self::$putenvBuffers[] = $buf;

        return 0 === (int) $ffi->putenv(\FFI::addr($buf[0]));
    }

    public static function getenv(string $name): string|false
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            try {
                $ptr = $ffi->getenv($name);
                if (null === $ptr) {
                    return false;
                }

                return \FFI::string($ptr);
            } catch (\Throwable) {
            }
        }

        $environ = VmEnvEnvironNative::enumerate();
        if (!\array_key_exists($name, $environ)) {
            return false;
        }

        return $environ[$name];
    }

    private static function copyCString(\FFI $ffi, string $value): \FFI\CData
    {
        $len = \strlen($value);
        $buf = $ffi->new('char['.($len + 1).']', false);
        if ($len > 0) {
            \FFI::memcpy($buf, $value, $len);
        }
        $buf[$len] = "\0";

        return $buf;
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
int putenv(char *string);
char *getenv(const char *name);
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
}
