<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * mkdir/rmdir/chmod/chown/chgrp without libc FFI (#8991, pairs {@see VmFsDirNative}).
 *
 * Bootstrap path when FFI is disabled: host PHP directory mutators under Zend VM.
 *
 * php-src: ext/standard/filestat.c — php_mkdir, php_chmod, php_chown
 */
final class VmFsDirPure
{
    private const PATH_MAX = 4096;

    public static function available(): bool
    {
        return \function_exists('mkdir');
    }

    public static function mkdir(string $path, int $mode, bool $recursive): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $mode &= 0777;
        if (!$recursive) {
            return @\mkdir($path, $mode);
        }
        $path = rtrim($path, '/');
        if ('' === $path || \strlen($path) >= self::PATH_MAX) {
            return false;
        }

        return @\mkdir($path, $mode, true);
    }

    public static function rmdir(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        return @\rmdir($path);
    }

    public static function chmod(string $path, int $permissions): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        return @\chmod($path, $permissions & 07777);
    }

    public static function chown(string $path, int $uid): bool
    {
        return self::chownAt($path, $uid, -1, false);
    }

    public static function lchown(string $path, int $uid): bool
    {
        return self::chownAt($path, $uid, -1, true);
    }

    public static function chgrp(string $path, int $gid): bool
    {
        return self::chownAt($path, -1, $gid, false);
    }

    public static function lchgrp(string $path, int $gid): bool
    {
        return self::chownAt($path, -1, $gid, true);
    }

    public static function chownAt(string $path, int $uid, int $gid, bool $linkOnly): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        $ok = true;
        if ($uid >= 0) {
            $ok = $linkOnly && \function_exists('lchown')
                ? @\lchown($path, $uid)
                : @\chown($path, $uid);
        }
        if ($ok && $gid >= 0) {
            $ok = $linkOnly && \function_exists('lchgrp')
                ? @\lchgrp($path, $gid)
                : @\chgrp($path, $gid);
        }

        return $ok;
    }
}
