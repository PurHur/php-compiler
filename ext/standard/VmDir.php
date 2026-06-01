<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM helpers for opendir/readdir/closedir/rewinddir (ext/standard/dir.c; issue #3235).
 *
 * php-src: ext/standard/dir.c — php_opendir, php_readdir, php_closedir, php_rewinddir
 */
final class VmDir
{
    private const HANDLE_BASE = 0x10000000;

    /** @var array<int, resource> */
    private static array $handles = [];

    private static int $nextHandleId = 0;

    /** @return int|false */
    public static function opendir(string $path)
    {
        $dh = @\opendir($path);
        if (false === $dh) {
            return false;
        }
        $id = ++self::$nextHandleId;
        self::$handles[$id] = $dh;

        return self::HANDLE_BASE + $id;
    }

    /** @return string|false */
    public static function readdir(int $handle)
    {
        $dh = self::lookup($handle);
        if (null === $dh) {
            return false;
        }
        $entry = @\readdir($dh);
        if (false === $entry) {
            return false;
        }

        return $entry;
    }

    public static function closedir(int $handle): void
    {
        $dh = self::lookup($handle);
        if (null === $dh) {
            return;
        }
        $slot = self::slot($handle);
        if (null === $slot) {
            return;
        }
        unset(self::$handles[$slot]);
        @\closedir($dh);
    }

    public static function rewinddir(int $handle): void
    {
        $dh = self::lookup($handle);
        if (null === $dh) {
            return;
        }
        @\rewinddir($dh);
    }

    public static function isValidHandle(int $handle): bool
    {
        $slot = self::slot($handle);

        return null !== $slot && isset(self::$handles[$slot]);
    }

    /** @return int|null */
    private static function slot(int $handle): ?int
    {
        if ($handle < self::HANDLE_BASE) {
            return null;
        }
        $slot = $handle - self::HANDLE_BASE;
        if ($slot <= 0) {
            return null;
        }

        return $slot;
    }

    /** @return resource|null */
    private static function lookup(int $handle)
    {
        $slot = self::slot($handle);
        if (null === $slot) {
            return null;
        }

        return self::$handles[$slot] ?? null;
    }
}
