<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * rename/copy/link/readlink/symlink without libc FFI (#5213, #12316, pairs {@see VmFsPathNative}).
 *
 * Bootstrap path when FFI is disabled: host PHP path mutators under Zend VM.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(rename), copy, link
 * php-src: ext/standard/filestat.c — readlink, symlink
 */
final class VmFsPathPure
{
    public static function available(): bool
    {
        return \function_exists('rename');
    }

    public static function rename(string $from, string $to): bool
    {
        if (str_contains($from, "\0") || str_contains($to, "\0")) {
            return false;
        }

        return @\rename($from, $to);
    }

    public static function copy(string $from, string $to): bool
    {
        if (str_contains($from, "\0") || str_contains($to, "\0")) {
            return false;
        }

        return @\copy($from, $to);
    }

    public static function link(string $target, string $link): bool
    {
        if (str_contains($target, "\0") || str_contains($link, "\0")) {
            return false;
        }

        return @\link($target, $link);
    }

    /** @return string|false */
    public static function readlink(string $path)
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $result = @\readlink($path);

        return false === $result ? false : $result;
    }

    public static function symlink(string $target, string $link): bool
    {
        if (str_contains($target, "\0") || str_contains($link, "\0")) {
            return false;
        }

        return @\symlink($target, $link);
    }
}
