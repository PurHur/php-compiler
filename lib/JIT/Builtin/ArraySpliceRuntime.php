<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableSpliceLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_splice() (#13643, #17967, #27075).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArraySpliceJitHelper} failed on
 * unresolved HashTable::spliceInPlace (`spliceinplace`, #27075). Emits
 * {@see HashTableSpliceLlvm} inline into the caller (peer {@see ArrayShiftRuntime} / #24025).
 *
 * VM SSOT: {@see \PHPCompiler\VM\HashTable::spliceInPlace()}
 * php-src: ext/standard/array.c — php_array_splice()
 */
final class ArraySpliceRuntime
{
    public static function splice(
        Context $context,
        JITVariable $array,
        Value $offset,
        Value $hasLength,
        Value $length,
        ?JITVariable $replacement,
        bool $hasReplacementArg
    ): Value {
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i1 = $context->getTypeFromString('int1');
        $hasReplFlag = $hasReplacementArg
            ? $i1->constInt(1, false)
            : $i1->constInt(0, false);
        $replHt = $htPtr->constNull();
        if ($hasReplacementArg && null !== $replacement) {
            $replHt = self::lowerReplacementHashTable($context, $replacement);
        }

        $removed = HashTableSpliceLlvm::spliceInPlace(
            $context,
            $ht,
            $offset,
            $hasLength,
            $length,
            $hasReplFlag,
            $replHt
        );
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);

        return $removed;
    }

    public static function ensureLinked(Context $context): void
    {
        // Inline emission in splice() — nothing to pre-link (#27075 / peer #24025).
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function lowerReplacementHashTable(Context $context, JITVariable $replacement): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($replacement->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $replacement);
        }
        if (JITVariable::TYPE_HASHTABLE === ($replacement->type & ~JITVariable::IS_NATIVE_ARRAY)) {
            return ArrayBuiltinHelper::loadHashTable($context, $replacement);
        }
        // Thin AOT boxes `[9]` as TYPE_VALUE (+ valueBoxHashtable). Appending that box nested
        // the replacement HT as one element (`foreach` saw `1:Array`) (#27075).
        if (
            $replacement->valueBoxHashtable
            || JITVariable::TYPE_VALUE === $replacement->type
            || JitValueBox::isValueOperand($replacement)
        ) {
            return ArrayBuiltinHelper::loadHashTable($context, $replacement);
        }

        $wrapped = HashTableHelper::alloc($context);
        ArrayBuiltinHelper::appendElement($context, $wrapped, $replacement);

        return $wrapped;
    }
}
