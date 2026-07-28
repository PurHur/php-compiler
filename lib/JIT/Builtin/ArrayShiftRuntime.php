<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableShiftLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT for array_shift() via {@see HashTableShiftLlvm} (#12672, #24025).
 *
 * Emits shift LLVM inline into the caller (not a separate ABI that returns a stack
 * alloca — that dangling `__value__*` UAF segfaulted after a correct echo of the
 * shifted value). Host/VM SSOT remains {@see \PHPCompiler\ext\standard\ArrayShiftJitHelper}.
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_shift)
 */
final class ArrayShiftRuntime
{
    public static function shift(Context $context, JITVariable $array): Value
    {
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return HashTableShiftLlvm::shiftFirst($context, $ht);
    }

    public static function ensureLinked(Context $context): void
    {
        // Inline emission in shift() — nothing to pre-link (#24025).
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }
}
