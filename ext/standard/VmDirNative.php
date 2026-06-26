<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * opendir/readdir/closedir/rewinddir — pure PHP via {@see VmDirPure} (#9034, #5494, #12235).
 *
 * Mirrors {@see \PHPCompiler\JIT\Builtin\StringDirJit} handle table (#5494, php-in-php).
 *
 * php-src: ext/standard/dir.c — PHP_FUNCTION(opendir), readdir, closedir, rewinddir
 */
final class VmDirNative
{
    private const HANDLE_BASE = 0x10000000;

    private const MAX_HANDLES = 256;

    /** @var array<int, array{entries: list<string>, pos: int}> */
    private static array $slots = [];

    private static int $nextSlot = 1;

    public static function available(): bool
    {
        return VmDirPure::available();
    }

    /** @return list<string>|false */
    public static function listSorted(string $path): array|false
    {
        return self::scanPath($path);
    }

    /** @return int|false */
    public static function opendir(string $path): int|false
    {
        $entries = self::scanPath($path);
        if (false === $entries) {
            return false;
        }

        for ($attempt = 0; $attempt < self::MAX_HANDLES; ++$attempt) {
            $slot = self::$nextSlot;
            ++self::$nextSlot;
            if (self::$nextSlot >= self::MAX_HANDLES) {
                self::$nextSlot = 1;
            }
            if (isset(self::$slots[$slot])) {
                continue;
            }
            self::$slots[$slot] = ['entries' => $entries, 'pos' => 0];

            return self::HANDLE_BASE + $slot;
        }

        return false;
    }

    /** @return string|false */
    public static function readdir(int $handle): string|false
    {
        $slot = self::slot($handle);
        if (null === $slot || !isset(self::$slots[$slot])) {
            return false;
        }
        $state = &self::$slots[$slot];
        if ($state['pos'] >= \count($state['entries'])) {
            return false;
        }

        return $state['entries'][$state['pos']++];
    }

    public static function closedir(int $handle): void
    {
        $slot = self::slot($handle);
        if (null === $slot) {
            return;
        }
        unset(self::$slots[$slot]);
    }

    public static function rewinddir(int $handle): void
    {
        $slot = self::slot($handle);
        if (null === $slot || !isset(self::$slots[$slot])) {
            return;
        }
        self::$slots[$slot]['pos'] = 0;
    }

    public static function isValidHandle(int $handle): bool
    {
        $slot = self::slot($handle);

        return null !== $slot && isset(self::$slots[$slot]);
    }

    /** @return list<string>|false */
    private static function scanPath(string $path): array|false
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        return VmDirPure::listSorted($path);
    }

    /** @return int|null */
    private static function slot(int $handle): ?int
    {
        if ($handle < self::HANDLE_BASE) {
            return null;
        }
        $slot = $handle - self::HANDLE_BASE;
        if ($slot <= 0 || $slot >= self::MAX_HANDLES) {
            return null;
        }

        return $slot;
    }
}
