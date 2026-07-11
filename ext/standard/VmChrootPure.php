<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chroot without libc FFI (#12192, pairs {@see VmChrootNative} / #8955 VmChdirPure).
 *
 * Bootstrap path when FFI is disabled: host chroot under Zend VM.
 *
 * php-src: ext/standard/dir.c — PHP_FUNCTION(chroot)
 */
final class VmChrootPure
{
    public static function available(): bool
    {
        return \function_exists('chroot');
    }

    public static function chroot(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        return @\chroot($path);
    }
}
