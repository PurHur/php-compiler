<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chdir without libc FFI (#8955, pairs {@see VmChdirNative}).
 *
 * Bootstrap path when FFI is disabled: host chdir under Zend VM.
 *
 * php-src: ext/standard/dir.c — PHP_FUNCTION(chdir)
 */
final class VmChdirPure
{
    public static function available(): bool
    {
        return \function_exists('chdir');
    }

    public static function chdir(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        return @\chdir($path);
    }
}
