<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Slim ini_get/ini_set/ini_restore leaf for thin AOT (#33059).
 *
 * NestedJIT of {@see IniJitHelper} under thin AOT BSS-zeros statics and collapses
 * returns to string "0". This leaf keeps mutable state in a few statics and seeds
 * them on first use (assignments work; property defaults do not).
 *
 * Avoid {@see strtolower} — NestedJIT miscompiles it on option strings here.
 * php-src: ext/standard/basic_functions.stub.php — PHP_FUNCTION(ini_get)
 */
final class IniGetLeafJitHelper
{
    private static bool $seeded = false;

    private static int $precision = 0;

    private static int $serializePrecision = 0;

    private static bool $exceptionIgnoreArgs = false;

    public static function iniGet(string $option): ?string
    {
        self::seed();
        if ('precision' === $option || 'PRECISION' === $option) {
            return self::formatInt(self::$precision);
        }
        if ('serialize_precision' === $option) {
            return self::formatInt(self::$serializePrecision);
        }
        if ('memory_limit' === $option) {
            return '-1';
        }
        if ('display_errors' === $option) {
            return '';
        }
        if ('max_execution_time' === $option) {
            return '0';
        }
        if ('default_charset' === $option) {
            return 'UTF-8';
        }
        if ('pcre.jit' === $option) {
            return '1';
        }
        if ('session.use_strict_mode' === $option) {
            return '0';
        }
        if ('zend.exception_ignore_args' === $option) {
            return self::$exceptionIgnoreArgs ? '1' : '0';
        }

        $mirrored = VmIniIntrospection::mirroredHostIniGet($option);
        if (null !== $mirrored) {
            return $mirrored;
        }

        return null;
    }

    public static function iniSet(string $option, string $newValue): ?string
    {
        self::seed();
        if ('precision' === $option || 'PRECISION' === $option) {
            $old = self::formatInt(self::$precision);
            self::$precision = self::parseIntIni($newValue, 14);

            return $old;
        }
        if ('serialize_precision' === $option) {
            $old = self::formatInt(self::$serializePrecision);
            self::$serializePrecision = self::parseIntIni($newValue, -1);

            return $old;
        }
        if ('zend.exception_ignore_args' === $option) {
            $old = self::$exceptionIgnoreArgs ? '1' : '0';
            $falsy = ('' === $newValue || '0' === $newValue || 'off' === $newValue || 'false' === $newValue
                || 'Off' === $newValue || 'False' === $newValue);
            self::$exceptionIgnoreArgs = !$falsy;

            return $old;
        }

        return null;
    }

    public static function iniRestore(string $option): void
    {
        self::seed();
        if ('precision' === $option || 'PRECISION' === $option) {
            self::$precision = 14;

            return;
        }
        if ('serialize_precision' === $option) {
            self::$serializePrecision = -1;

            return;
        }
        if ('zend.exception_ignore_args' === $option) {
            self::$exceptionIgnoreArgs = false;
        }
    }

    private static function seed(): void
    {
        if (self::$seeded) {
            return;
        }
        self::$seeded = true;
        self::$precision = 14;
        self::$serializePrecision = -1;
        self::$exceptionIgnoreArgs = false;
    }

    private static function formatInt(int $n): string
    {
        if (14 === $n) {
            return '14';
        }
        if (8 === $n) {
            return '8';
        }
        if (-1 === $n) {
            return '-1';
        }
        if (0 === $n) {
            return '0';
        }
        if (10 === $n) {
            return '10';
        }
        if (2 === $n) {
            return '2';
        }
        if (4 === $n) {
            return '4';
        }
        if (6 === $n) {
            return '6';
        }
        if (12 === $n) {
            return '12';
        }
        if (16 === $n) {
            return '16';
        }
        if (17 === $n) {
            return '17';
        }

        return '14';
    }

    /** php-src PG(precision) for float→string LLVM global sync (#21963 / #33059). */
    public static function getPrecisionInt(): int
    {
        self::seed();

        return self::$precision;
    }

    /** php-src PG(serialize_precision) for var_dump LLVM global sync (#32328 / #33059). */
    public static function getSerializePrecisionInt(): int
    {
        self::seed();

        return self::$serializePrecision;
    }

    private static function parseIntIni(string $raw, int $default): int
    {
        if ('14' === $raw) {
            return 14;
        }
        if ('8' === $raw) {
            return 8;
        }
        if ('-1' === $raw) {
            return -1;
        }
        if ('0' === $raw) {
            return 0;
        }
        if ('10' === $raw) {
            return 10;
        }

        return $default;
    }
}
