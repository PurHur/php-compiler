<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * include_path stack for compiled JIT/AOT modules (#9245, php-in-PHP, #20877).
 *
 * Two-slot NestedJIT-safe model under thin AOT:
 * - `$current` — active include_path (get never scans)
 * - `$previous` — one restore frame (legacy restore_include_path; removed in PHP 8.0+)
 * Avoids nullable statics and explode/implode (NestedJIT thin-AOT hazards).
 * Host SAPI seed lives in {@see VmIncludePath} (#10461).
 * php-src: ext/standard/basic_functions.c — php_get_include_path / php_set_include_path
 */
final class IncludePathJitHelper
{
    private static string $current = '.';

    private static string $previous = '';

    private static bool $seeded = false;

    /** True before first get/set/seed (VmIncludePath host-ini seed). */
    public static function isUninitialized(): bool
    {
        return !self::$seeded;
    }

    /** Seed stack once (host ini or default "."); no-op if already initialized. */
    public static function seed(string $path): void
    {
        if (self::$seeded) {
            return;
        }
        self::$seeded = true;
        self::$current = '' !== $path ? $path : '.';
        self::$previous = '';
    }

    private static function ensureSeeded(): void
    {
        if (!self::$seeded) {
            self::$seeded = true;
            self::$current = '.';
            self::$previous = '';
        }
    }

    public static function get(): string
    {
        self::ensureSeeded();

        return self::$current;
    }

    /** @return string previous include_path */
    public static function set(string $newPath): string
    {
        self::ensureSeeded();
        $old = self::$current;
        self::$current = $newPath;

        return $old;
    }

    /**
     * php-src ext/standard/basic_functions.c — zend_alter_ini_entry(include_path) rejects empty path.
     *
     * @return string|false previous include_path, or false when $newPath is empty after coercion
     */
    public static function push(string $newPath): string|false
    {
        if ('' === $newPath) {
            return false;
        }
        self::ensureSeeded();
        $old = self::$current;
        // Assign from local — NestedJIT static←static stores can miss the global (#20877).
        self::$previous = $old;
        self::$current = $newPath;

        return $old;
    }

    public static function restore(): void
    {
        self::ensureSeeded();
        if ('' === self::$previous) {
            return;
        }
        $prev = self::$previous;
        self::$current = $prev;
        self::$previous = '';
    }

    /**
     * @return string|false absolute path when found
     */
    public static function resolveIncludePathZend(string $filename): string|false
    {
        return IncludePathResolveJitHelper::resolve($filename, self::get());
    }
}
