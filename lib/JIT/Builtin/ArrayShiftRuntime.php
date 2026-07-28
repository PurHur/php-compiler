<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Call\HashTableShiftFirst;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_shift() via NestedJIT-safe HashTable::shiftFirst LLVM (#24025).
 *
 * Pure LLVM through {@see HashTableShiftFirst} — must not NestedJIT-compile
 * {@see \PHPCompiler\ext\standard\ArrayShiftJitHelper} for standalone AOT (Variable
 * object return ABI exports TYPE_OBJECT; peer #23974 sliceCopy recursion class).
 *
 * VM SSOT remains {@see \PHPCompiler\ext\standard\ArrayShiftJitHelper} / {@see array_shift}.
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_shift)
 */
final class ArrayShiftRuntime
{
    public static function shift(Context $context, JITVariable $array): Value
    {
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);

        return (new HashTableShiftFirst())->call($context, $htVar);
    }

    public static function ensureLinked(Context $context): void
    {
        // HashTableShiftFirst emits into the current insert block — no ABI symbol.
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }
}
