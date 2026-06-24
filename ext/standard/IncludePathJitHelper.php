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
    private static ?string $stack = null;

    /** Seed from host SAPI ini on first access (issue #10461). */
    private static function ensureStack(): string
    {
        if (null !== self::$stack) {
            return self::$stack;
        }
        $ini = @ini_get('include_path');
        self::$stack = (false !== $ini && '' !== $ini) ? $ini : '.';

        return self::$stack;
    }

    public static function get(): string
    {
        $parts = \explode("\0", self::ensureStack());

        return $parts[\count($parts) - 1];
    }

    /** @return string previous include_path */
    public static function set(string $newPath): string
    {
        $old = self::get();
        $stack = self::ensureStack();
        $parts = \explode("\0", $stack);
        $parts[\count($parts) - 1] = $newPath;
        self::$stack = \implode("\0", $parts);

        return $old;
    }

    /** @return string previous include_path */
    public static function push(string $newPath): string
    {
        $old = self::get();
        self::$stack = self::ensureStack()."\0".$newPath;

        return $old;
    }

    public static function restore(): void
    {
        $stack = self::ensureStack();
        $pos = \strrpos($stack, "\0");
        if (false !== $pos) {
            self::$stack = \substr($stack, 0, $pos);
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
