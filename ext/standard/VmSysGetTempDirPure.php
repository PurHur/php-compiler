<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sys_get_temp_dir() without libc realpath FFI (#8180, #12155).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(sys_get_temp_dir)
 */
final class VmSysGetTempDirPure
{
    public static function available(): bool
    {
        return true;
    }

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

        if (\function_exists('realpath')) {
            $resolved = @\realpath($path);
            if (false !== $resolved && '' !== $resolved) {
                return $resolved;
            }
        }

        return $path;
    }
}
