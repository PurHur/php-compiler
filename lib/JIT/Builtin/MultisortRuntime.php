<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_multisort() coupled packed paths via LLVM `__multisort__packed` (#26908).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\MultisortJitHelper} aborts under thin standalone
 * AOT (same NestedJIT HashTable/Traversable hole as SortJitHelper — see #24010). Emit the
 * coupled bubble sort in {@see Type\HashTable::implementMultisortPacked()} instead.
 *
 * SSOT (VM): {@see \PHPCompiler\ext\standard\array_multisort}
 * php-src: ext/standard/array.c — php_array_multisort
 */
final class MultisortRuntime
{
    private const ABI_MULTISORT_PACKED = '__multisort__packed';

    /**
     * @param list<JITVariable> $arrays
     */
    public static function multisortPacked(Context $context, array $arrays, bool $descending): void
    {
        if (\count($arrays) < 2) {
            throw new \LogicException(
                'array_multisort() requires at least two array arguments in this compiler build'
            );
        }

        $sources = [];
        foreach ($arrays as $i => $array) {
            if ($i > 0 && ArrayBuiltinHelper::isNativeArray($array->type)) {
                throw new \LogicException(
                    'array_multisort() cannot compile fixed-size literal arrays in JIT/AOT yet; assign to variables first'
                );
            }
            // By-ref mutator: SEPARATE_ARRAY before in-place multisort (php-src
            // php_array_multisort / Zend SEPARATE_ARRAY / #36397). M5 assert here
            // (before packVariables addrefs the HT into the sources list).
            $ht = self::argToHashtableForWrite($context, $array);
            Refcount::emitAssertExclusiveCall($context, $ht);
            $sources[] = $ht;
        }

        self::ensureLinked($context);
        $packed = self::packHashtablePtrArray($context, $sources);
        $context->builder->call(
            $context->lookupFunction(self::ABI_MULTISORT_PACKED),
            HashTableHelper::loadHashtablePointer($context, $packed),
            $context->getTypeFromString('int1')->constInt($descending ? 1 : 0, false)
        );
        foreach ($arrays as $i => $array) {
            // In-place LLVM multisort — only rebind native int[] into a value box.
            // Writing the same HT back into a TYPE_VALUE box valueDelref's it (#36388).
            if (ArrayBuiltinHelper::isNativeArray($array->type)) {
                HashTableHelper::storeHashtableInArrayVariable($context, $array, $sources[$i]);
            }
        }
    }

    public static function ensureLinked(Context $context): void
    {
        $context->type->hashtable->ensureMultisortPacked();
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function argToHashtableForWrite(Context $context, JITVariable $arg): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $arg);
        }

        return HashTableHelper::separateContainerForWrite($context, $arg);
    }

    /**
     * @param list<Value> $sources
     */
    private static function packHashtablePtrArray(Context $context, array $sources): JITVariable
    {
        $vars = [];
        foreach ($sources as $source) {
            $vars[] = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $source);
        }

        return HashTableHelper::packVariables($context, $vars);
    }
}
