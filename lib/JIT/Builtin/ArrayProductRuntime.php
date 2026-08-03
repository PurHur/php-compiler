<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayProductLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT for array_product() via {@see ArrayProductLlvm} (#12591, #26968).
 *
 * Emits the fold LLVM inline into the caller (not a NestedJIT ABI that returns
 * {@see \PHPCompiler\VM\Variable} — that writeObject'd the Variable / dangling
 * alloca under thin standalone AOT; peer ArraySum #24167).
 *
 * Host/VM SSOT remains {@see \PHPCompiler\ext\standard\ArrayProductJitHelper}.
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_product)
 */
final class ArrayProductRuntime
{
    public static function product(Context $context, JITVariable $array): Value
    {
        $ht = ArrayBuiltinHelper::isNativeArray($array->type)
            ? ArrayBuiltinHelper::nativeListToHashTable($context, $array)
            : ArrayBuiltinHelper::loadHashTable($context, $array);

        return ArrayProductLlvm::product($context, $ht);
    }

    public static function ensureLinked(Context $context): void
    {
        // Inline emission in product() — nothing to pre-link (#26968).
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }
}
