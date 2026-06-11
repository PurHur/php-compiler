<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * fnmatch() for VM — libc fnmatch(3) FFI, then {@see VmFnmatchPure} (#8016, #7756).
 *
 * php-src: ext/standard/fnmatch.c — PHP_FUNCTION(fnmatch)
 */
final class VmFnmatch
{
    public const FNM_NOESCAPE = 2;
    public const FNM_PATHNAME = 1;
    public const FNM_PERIOD = 4;
    public const FNM_CASEFOLD = 16;

    private static ?\FFI $ffi = null;

    public static function available(): bool
    {
        return null !== self::ffi() || VmFnmatchPure::available();
    }

    public static function match(string $pattern, string $string, int $flags = 0): bool
    {
        if (self::ffiEnabled()) {
            $libc = self::libcMatch($pattern, $string, $flags);
            if (null !== $libc) {
                return $libc;
            }
        }

        return VmFnmatchPure::match($pattern, $string, $flags);
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }

    /**
     * @return bool|null null when FFI/libc path unavailable
     */
    private static function libcMatch(string $pattern, string $string, int $flags): ?bool
    {
        if (!self::ffiEnabled() || !\extension_loaded('ffi')) {
            return null;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        $sysFlags = self::phpFlagsToSystem($flags);
        $rc = (int) $ffi->fnmatch($pattern, $string, $sysFlags);

        return 0 === $rc;
    }

    private static function phpFlagsToSystem(int $phpFlags): int
    {
        $sys = 0;
        /** @var array<int, int> $map */
        $map = [
            self::FNM_NOESCAPE => self::FNM_NOESCAPE,
            self::FNM_PATHNAME => self::FNM_PATHNAME,
            self::FNM_PERIOD => self::FNM_PERIOD,
            self::FNM_CASEFOLD => self::FNM_CASEFOLD,
        ];
        foreach ($map as $phpBit => $sysBit) {
            if (0 !== ($phpFlags & $phpBit)) {
                $sys |= $sysBit;
            }
        }

        return $sys;
    }

    private static function ffi(): ?\FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            return null;
        }

        $cdef = <<<'CDEF'
int fnmatch(const char *pattern, const char *string, int flags);
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
