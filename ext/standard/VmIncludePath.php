<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * include_path stack (php-src PG(include_path) / zend_ini; issues #3223, #6051, #10461, #20877).
 *
 * Delegates to {@see IncludePathJitHelper} — shared storage for VM + JIT/AOT (#9245).
 * Host SAPI ini seed stays here (interpreted PHP) so NestedJIT helpers stay ini_get-free.
 */
final class VmIncludePath
{
    private static bool $hostIniSeeded = false;

    private static function seedHostIniOnce(): void
    {
        if (self::$hostIniSeeded || !IncludePathJitHelper::isUninitialized()) {
            return;
        }
        self::$hostIniSeeded = true;
        $ini = @\ini_get('include_path');
        IncludePathJitHelper::seed((false !== $ini && '' !== $ini) ? $ini : '.');
    }

    public static function get(): string
    {
        self::seedHostIniOnce();

        return IncludePathJitHelper::get();
    }

    /** @return string previous include_path */
    public static function set(string $newPath): string
    {
        self::seedHostIniOnce();

        return IncludePathJitHelper::set($newPath);
    }

    /** @return string|false previous include_path, or false when $newPath is empty */
    public static function push(string $newPath): string|false
    {
        self::seedHostIniOnce();

        return IncludePathJitHelper::push($newPath);
    }

    public static function restore(): void
    {
        self::seedHostIniOnce();
        IncludePathJitHelper::restore();
    }
}
