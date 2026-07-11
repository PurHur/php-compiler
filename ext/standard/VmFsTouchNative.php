<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * touch() for VM — pure PHP via {@see VmFsTouchPure} (#8430, #8999, #12145).
 *
 * php-src: ext/standard/filestat.c — php_touch
 */
final class VmFsTouchNative
{
    public static function available(): bool
    {
        return VmFsTouchPure::available();
    }

    public static function touch(string $path, ?int $mtime = null, ?int $atime = null): bool
    {
        return VmFsTouchPure::touch($path, $mtime, $atime);
    }
}
