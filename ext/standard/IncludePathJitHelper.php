<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * include_path stack for compiled JIT/AOT modules (#9245, php-in-PHP).
 *
 * Stack uses a NUL-delimited static string (JIT cannot lower static array push/pop yet).
 * VM SSOT delegates via {@see VmIncludePath}.
 * php-src: ext/standard/basic_functions.c — php_get_include_path / php_set_include_path
 */
final class IncludePathJitHelper
{
    private static string $stack = '.';

    public static function get(): string
    {
        $parts = \explode("\0", self::$stack);

        return $parts[\count($parts) - 1];
    }

    /** @return string previous include_path */
    public static function set(string $newPath): string
    {
        $old = self::get();
        $parts = \explode("\0", self::$stack);
        $parts[\count($parts) - 1] = $newPath;
        self::$stack = \implode("\0", $parts);

        return $old;
    }

    /** @return string previous include_path */
    public static function push(string $newPath): string
    {
        $old = self::get();
        self::$stack = self::$stack."\0".$newPath;

        return $old;
    }

    public static function restore(): void
    {
        $pos = \strrpos(self::$stack, "\0");
        if (false !== $pos) {
            self::$stack = \substr(self::$stack, 0, $pos);
        }
    }

    /**
     * @return string|false absolute path when found
     */
    public static function resolveIncludePathZend(string $filename): string|false
    {
        return IncludePathResolveJitHelper::resolve($filename, self::get());
    }
}
