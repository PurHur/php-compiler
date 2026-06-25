<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * get_class() class-id lookup for compiled JIT/AOT modules (#10222, php-in-PHP).
 *
 * Per-TU name table is embedded when {@see \PHPCompiler\JIT\Builtin\GetClassRuntime} compiles this helper.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_class)
 */
final class GetClassJitHelper
{
    /** @var array<int, string> */
    private static array $namesById = [];

    /**
     * @param array<int, string> $namesById compile-unit class table
     */
    public static function seedNamesById(array $namesById): void
    {
        self::$namesById = $namesById;
    }

    public static function classNameFromClassId(int $classId): string
    {
        return self::$namesById[$classId] ?? '';
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$namesById = [];
    }
}
