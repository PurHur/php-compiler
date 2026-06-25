<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmIteratorForeach;
use PHPLLVM\Value;

/**
 * JIT trampoline for foreach hashtable / SplObjectStorage / WeakMap lowering (#10080).
 *
 * SSOT: {@see \PHPCompiler\VM\VmIteratorForeach}
 */
final class IteratorHelper
{
    public static function compileReset(Context $context, Variable $array, ?string $containerUserType = null): void
    {
        VmIteratorForeach::compileReset($context, $array, $containerUserType);
    }

    public static function compileValid(
        Context $context,
        Variable $array,
        ?string $containerUserType = null
    ): Value {
        return VmIteratorForeach::compileValid($context, $array, $containerUserType);
    }

    public static function compileKey(
        Context $context,
        Variable $array,
        ?string $containerUserType = null
    ): Variable {
        return VmIteratorForeach::compileKey($context, $array, $containerUserType);
    }

    public static function compileValue(
        Context $context,
        Variable $array,
        ?string $containerUserType = null
    ): Variable {
        return VmIteratorForeach::compileValue($context, $array, $containerUserType);
    }

    public static function compileValueByRef(
        Context $context,
        Variable $array,
        ?string $containerUserType = null,
        ?JIT $jit = null
    ): Variable {
        return VmIteratorForeach::compileValueByRef($context, $array, $containerUserType, $jit);
    }
}
