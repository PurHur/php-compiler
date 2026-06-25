<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for enum case property fetch + singleton globals (#9938, php-in-PHP).
 *
 * php-src: Zend/zend_enum.c — enum case object properties name/value
 * SSOT: {@see EnumCaseSupport}
 */
final class EnumCasePropertyJitHelper
{
    public const SLOT_NAME = 0;

    public const SLOT_VALUE = 1;

    public static function isBuiltinPropertyName(string $nameLc): bool
    {
        return 'name' === $nameLc || 'value' === $nameLc;
    }

    public static function slotIndexForBuiltinProperty(string $nameLc): int
    {
        return 'name' === $nameLc ? self::SLOT_NAME : self::SLOT_VALUE;
    }

    public static function enumStringCastErrorMessage(string $className): string
    {
        return 'Object of class '.$className.' could not be converted to string';
    }

    public static function singletonGlobalName(int $enumClassId, string $caseKey): string
    {
        return 'php_compiler_enum_case_singleton_'.$enumClassId.'_'.$caseKey;
    }
}
