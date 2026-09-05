<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Static bridge for filter_* constant lookup owned by ext/filter (#36204).
 *
 * lib/VM/Context must not import PHPCompiler\\ext\\filter; Module::init registers.
 *
 * php-src: ext/filter/filter.c — FILTER_* constants.
 */
final class FilterVmRuntimeSupport
{
    /** @var null|callable(string): (?Variable) */
    private static $variableForName = null;

    public static function clear(): void
    {
        self::$variableForName = null;
    }

    /** @param callable(string): (?Variable) $hook */
    public static function setVariableForName(callable $hook): void
    {
        self::$variableForName = $hook;
    }

    public static function variableForName(string $name): ?Variable
    {
        if (null === self::$variableForName) {
            return null;
        }

        return (self::$variableForName)($name);
    }
}
