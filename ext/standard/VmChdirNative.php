<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chdir for VM — {@see VmChdirPure} SSOT, no libc FFI (#8955, #12154).
 *
 * php-src: ext/standard/dir.c — PHP_FUNCTION(chdir)
 */
final class VmChdirNative
{
    public static function available(): bool
    {
        return VmChdirPure::available();
    }

    public static function chdir(string $path): bool
    {
        return VmChdirPure::chdir($path);
    }
}
