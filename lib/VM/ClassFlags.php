<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Packed class declaration flags stored on TYPE_DECLARE_CLASS arg3 (#1360, #144).
 */
final class ClassFlags
{
    public const READONLY = 1;

    public const ABSTRACT = 2;

    public const STATIC = 4;

    public const FINAL = 8;

    public static function pack(int $classFlags): int
    {
        $packed = 0;
        if (ClassReadonly::fromClassFlags($classFlags)) {
            $packed |= self::READONLY;
        }
        if (ClassAbstract::fromClassFlags($classFlags)) {
            $packed |= self::ABSTRACT;
        }
        if (ClassStatic::fromClassFlags($classFlags)) {
            $packed |= self::STATIC;
        }
        if (ClassFinal::fromClassFlags($classFlags)) {
            $packed |= self::FINAL;
        }

        return $packed;
    }

    public static function isReadonly(int $packed): bool
    {
        return 0 !== ($packed & self::READONLY);
    }

    public static function isAbstract(int $packed): bool
    {
        return 0 !== ($packed & self::ABSTRACT);
    }

    public static function isStatic(int $packed): bool
    {
        return 0 !== ($packed & self::STATIC);
    }

    public static function isFinal(int $packed): bool
    {
        return 0 !== ($packed & self::FINAL);
    }
}
