<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayUniqueLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_unique() (#12341, #27066).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayUniqueJitHelper} segfaults after
 * build (peer ArrayFlip #26970). Call-site LLVM via {@see ArrayUniqueLlvm}; flags are resolved
 * at the PHP call site so no runtime flags ABI is required.
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\ArrayUniqueJitHelper}
 * php-src: ext/standard/array.c — php_array_unique()
 */
final class ArrayUniqueRuntime
{
    public static function unique(Context $context, JITVariable $array, int $flags): Value
    {
        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::isNativeArray($array->type)
            ? ArrayBuiltinHelper::nativeListToHashTable($context, $array)
            : ArrayBuiltinHelper::loadHashTable($context, $array);

        return ArrayUniqueLlvm::uniqueHashTable($context, $ht, $flags);
    }

    public static function ensureLinked(Context $context): void
    {
        // strval / value-box helpers used by ArrayUniqueLlvm are registered with the type system.
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }
}
