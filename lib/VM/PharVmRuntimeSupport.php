<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Static bridge for Phar VM helpers owned by ext/phar (#36204).
 *
 * lib/ must not import PHPCompiler\ext\phar; Module::init registers callables.
 *
 * php-src: ext/phar/phar_object.c — Phar::running().
 */
final class PharVmRuntimeSupport
{
    /** @var null|callable(string, bool): string */
    private static $runningPath = null;

    public static function clear(): void
    {
        self::$runningPath = null;
    }

    /** @param callable(string, bool): string $hook */
    public static function setRunningPath(callable $hook): void
    {
        self::$runningPath = $hook;
    }

    public static function runningPath(string $scriptPath, bool $retPhar): string
    {
        if (null === self::$runningPath) {
            return '';
        }

        return (self::$runningPath)($scriptPath, $retPhar);
    }
}
