<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * touch()/mkdir()/tempnam() for compiled JIT/AOT modules (#8999, php-in-PHP).
 *
 * SSOT: {@see VmFsTouchNative}, {@see VmFsDirNative}, {@see VmFsTempnam}
 * php-src: ext/standard/filestat.c, ext/standard/file.c
 */
final class FsDirJitHelper
{
    private static bool $tempnamPendingNotice = false;

    public static function touch(string $path, int $mtime, int $atime): bool
    {
        return VmFsTouchNative::touch(
            $path,
            $mtime < 0 ? null : $mtime,
            $atime < 0 ? null : $atime
        );
    }

    public static function mkdir(string $path, int $mode, bool $recursive): bool
    {
        return VmFsDirNative::mkdir($path, $mode, $recursive);
    }

    /** @return string|null null when tempnam() fails */
    public static function tempnam(string $directory, string $prefix): ?string
    {
        self::$tempnamPendingNotice = false;
        if ('' === $directory) {
            return null;
        }
        $pfx = VmFsTempnam::normalizePrefix($prefix);
        $path = VmFsTempnamNative::mkstemp($directory, $pfx);
        if (false !== $path) {
            return $path;
        }
        self::$tempnamPendingNotice = true;
        $fallback = VmSysGetTempDirNative::resolve();
        if ('' === $fallback) {
            return null;
        }
        $path = VmFsTempnamNative::mkstemp($fallback, $pfx);

        return false !== $path ? $path : null;
    }

    public static function consumeTempnamNotice(): bool
    {
        $pending = self::$tempnamPendingNotice;
        self::$tempnamPendingNotice = false;

        return $pending;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$tempnamPendingNotice = false;
    }
}
