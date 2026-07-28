<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArraySumLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT for array_sum() via {@see ArraySumLlvm} (#12590, #24167).
 *
 * Emits the fold LLVM inline into the caller (not a NestedJIT ABI that returns
 * {@see \PHPCompiler\VM\Variable} — that writeObject'd the Variable / dangling
 * alloca under thin standalone AOT; peer ArrayShift #24025).
 *
 * Host/VM SSOT remains {@see \PHPCompiler\ext\standard\ArraySumJitHelper}.
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_sum)
 */
final class ArraySumRuntime
{
    public static function sum(Context $context, JITVariable $array): Value
    {
        $ht = ArrayBuiltinHelper::isNativeArray($array->type)
            ? ArrayBuiltinHelper::nativeListToHashTable($context, $array)
            : ArrayBuiltinHelper::loadHashTable($context, $array);

        return ArraySumLlvm::sum($context, $ht);
    }

    public static function ensureLinked(Context $context): void
    {
        // Inline emission in sum() — nothing to pre-link (#24167).
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }
}
