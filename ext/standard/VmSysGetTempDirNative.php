<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sys_get_temp_dir() for VM without host \sys_get_temp_dir() (#8180, pairs #5044).
 *
 * Mirrors {@see \PHPCompiler\JIT\Builtin\StringFsDirJit::emitSysGetTempDir} — TMPDIR/TEMP/TMP then /tmp,
 * realpath when available.
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(sys_get_temp_dir)
 */
final class VmSysGetTempDirNative
{
    private const PATH_MAX = 4096;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function resolve(): string
    {
        foreach (['TMPDIR', 'TEMP', 'TMP'] as $name) {
            $value = VmEnv::getenv($name);
            if (false !== $value && '' !== $value) {
                $resolved = self::realpathOrOriginal($value);
                if ('' !== $resolved) {
                    return $resolved;
                }
            }
        }

        $fallback = self::realpathOrOriginal('/tmp');

        return '' !== $fallback ? $fallback : '/tmp';
    }

    private static function realpathOrOriginal(string $path): string
    {
        if (str_contains($path, "\0")) {
            return '';
        }

        $resolved = self::resolveViaFfiRealpath($path);
        if (false !== $resolved && '' !== $resolved) {
            return $resolved;
        }

        return $path;
    }

    /**
     * @return string|false
     */
    private static function resolveViaFfiRealpath(string $path)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $pathC = $ffi->new('char['.(\strlen($path) + 1).']', false);
            \FFI::memcpy($pathC, $path, \strlen($path));
            $pathC[\strlen($path)] = "\0";
            $resolved = $ffi->realpath(\FFI::addr($pathC[0]), null);
            if (null === $resolved) {
                return false;
            }
            $result = \FFI::string($resolved);
            $ffi->free($resolved);
            if ('' === $result) {
                return false;
            }

            return $result;
        } catch (\Throwable) {
            return false;
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
char *realpath(const char *path, char *resolved_path);
void free(void *ptr);
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
