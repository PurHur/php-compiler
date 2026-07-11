<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * unlink() without libc unlink(2) FFI (#12194, pairs {@see VmFsUnlink} / #12145 VmFsTouchPure).
 *
 * Bootstrap path when FFI is disabled: host unlink under Zend VM.
 *
 * php-src: ext/standard/filestat.c — php_unlink
 */
final class VmFsUnlinkPure
{
    public static function available(): bool
    {
        return \function_exists('unlink');
    }

    public static function unlink(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        return @\unlink($path);
    }
}
