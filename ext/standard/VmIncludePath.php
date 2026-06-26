<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * include_path stack (php-src PG(include_path) / zend_ini; issues #3223, #6051).
 *
 * Delegates to {@see IncludePathJitHelper} — shared storage for VM + JIT/AOT (#9245).
 */
final class VmIncludePath
{
    public static function get(): string
    {
        return IncludePathJitHelper::get();
    }

    /** @return string previous include_path */
    public static function set(string $newPath): string
    {
        return IncludePathJitHelper::set($newPath);
    }

    /** @return string|false previous include_path, or false when $newPath is empty */
    public static function push(string $newPath): string|false
    {
        return IncludePathJitHelper::push($newPath);
    }

    public static function restore(): void
    {
        IncludePathJitHelper::restore();
    }
}
