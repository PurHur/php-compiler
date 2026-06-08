<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * include_path stack (php-src PG(include_path) / zend_ini; issues #3223, #6051).
 *
 * Default matches Zend: ".". {@see set_include_path()} replaces the active entry;
 * {@see restore_include_path()} pops the saved stack.
 */
final class VmIncludePath
{
    /** @var list<string> */
    private static array $stack = ['.'];

    public static function get(): string
    {
        return self::$stack[\count(self::$stack) - 1];
    }

    /** @return string previous include_path */
    public static function set(string $newPath): string
    {
        $old = self::get();
        self::$stack[\count(self::$stack) - 1] = $newPath;

        return $old;
    }

    public static function restore(): void
    {
        if (\count(self::$stack) > 1) {
            \array_pop(self::$stack);
        }
    }

    /** Push a new include_path while preserving the previous value on the stack. */
    public static function push(string $newPath): string
    {
        $old = self::get();
        self::$stack[] = $newPath;

        return $old;
    }
}
