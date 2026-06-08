<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Process environment enumeration without host PHP \getenv() loops (#5075, #5079, #5345).
 *
 * php-src: ext/standard/basic_functions.c — zif_getenv argc==0 via environ walk.
 */
final class VmEnvEnvironNative
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    /**
     * @return array<string, string>
     */
    public static function enumerate(): array
    {
        $viaFfi = self::enumerateViaFfi();
        if ([] !== $viaFfi) {
            return $viaFfi;
        }

        if ('Linux' === \PHP_OS_FAMILY) {
            return self::enumerateLinuxProcEnviron();
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private static function enumerateViaFfi(): array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return [];
        }

        try {
            $environPtr = $ffi->environ;
            if (null === $environPtr) {
                return [];
            }

            $result = [];
            for ($i = 0; ; ++$i) {
                $entry = $environPtr[$i];
                if (null === $entry) {
                    break;
                }
                $line = \FFI::string($entry);
                if ('' === $line) {
                    continue;
                }
                $eq = strpos($line, '=');
                if (false === $eq) {
                    continue;
                }
                $result[substr($line, 0, $eq)] = substr($line, $eq + 1);
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Linux bootstrap without FFI: /proc/self/environ (issue #5079 pattern).
     *
     * @return array<string, string>
     */
    private static function enumerateLinuxProcEnviron(): array
    {
        if (!\is_readable('/proc/self/environ')) {
            return [];
        }

        $raw = @\file_get_contents('/proc/self/environ');
        if (false === $raw || '' === $raw) {
            return [];
        }

        $result = [];
        foreach (explode("\0", $raw) as $pair) {
            if ('' === $pair) {
                continue;
            }
            $eq = strpos($pair, '=');
            if (false === $eq) {
                continue;
            }
            $result[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
        }

        return $result;
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
extern char **environ;
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
