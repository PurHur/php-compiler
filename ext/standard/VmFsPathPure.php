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
        // Embedded-NUL reject is handled by rename_() / JitStringBuiltinArg on the
        // JIT leaf; keep this Pure path free of NestedJIT-fragile scans (#29141).
        if (null !== VmFsPhpWrapper::renameWarningMessage($from, $to)) {
            return false;
        }

        return @\rename($from, $to);
    }

    public static function copy(string $from, string $to): bool
    {
        if (self::pathHasNulByte($from) || self::pathHasNulByte($to)) {
            return false;
        }

        return @\copy($from, $to);
    }

    public static function link(string $target, string $link): bool
    {
        if (self::pathHasNulByte($target) || self::pathHasNulByte($link)) {
            return false;
        }

        return @\link($target, $link);
    }

    /** @return string|false */
    public static function readlink(string $path)
    {
        if (self::pathHasNulByte($path)) {
            return false;
        }
        $result = @\readlink($path);

        return false === $result ? false : $result;
    }

    public static function symlink(string $target, string $link): bool
    {
        if (self::pathHasNulByte($target) || self::pathHasNulByte($link)) {
            return false;
        }

        return @\symlink($target, $link);
    }

    /** NestedJIT-safe embedded-NUL check (#29141) — avoids str_contains("\0") always-true. */
    private static function pathHasNulByte(string $path): bool
    {
        $n = \strlen($path);
        for ($i = 0; $i < $n; ++$i) {
            if ("\0" === $path[$i]) {
                return true;
            }
        }

        return false;
    }
}
