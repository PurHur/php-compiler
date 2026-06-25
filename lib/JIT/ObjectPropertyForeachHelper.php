<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmObjectPropertyForeach;
use PHPLLVM\Value;

/**
 * JIT trampoline for foreach over object instance properties (#3661, #5034, #10239).
 *
 * SSOT: {@see \PHPCompiler\VM\VmObjectPropertyForeach}
 */
final class ObjectPropertyForeachHelper
{
    public static function canLower(Context $context, Variable $container, ?string $containerUserType): bool
    {
        return VmObjectPropertyForeach::canLower($context, $container, $containerUserType);
    }

    public static function compileReset(Context $context, Variable $container, Variable $slotKey): void
    {
        VmObjectPropertyForeach::compileReset($context, $container, $slotKey);
    }

    public static function compileValid(
        Context $context,
        Variable $slotKey,
        ?string $containerUserType = null
    ): Value {
        return VmObjectPropertyForeach::compileValid($context, $slotKey, $containerUserType);
    }

    public static function compileKey(
        Context $context,
        Variable $slotKey,
        ?string $containerUserType = null
    ): Variable {
        return VmObjectPropertyForeach::compileKey($context, $slotKey, $containerUserType);
    }

    public static function compileValue(
        Context $context,
        Variable $slotKey,
        ?string $containerUserType = null
    ): Variable {
        return VmObjectPropertyForeach::compileValue($context, $slotKey, $containerUserType);
    }

    public static function compileValueByRef(
        Context $context,
        Variable $slotKey,
        ?string $containerUserType = null
    ): Variable {
        return VmObjectPropertyForeach::compileValueByRef($context, $slotKey, $containerUserType);
    }
}
