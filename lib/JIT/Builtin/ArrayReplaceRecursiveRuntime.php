<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableReplaceRecursiveLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for array_replace_recursive() via call-site LLVM (#12638, #26977).
 *
 * Emits {@see HashTableReplaceRecursiveLlvm} at the call site (peer array_splice #27075 /
 * HashTableSpliceLlvm). NestedJIT of ArrayReplaceRecursiveJitHelper returns a HT that
 * thin-standalone json_encode cannot walk (exportKeyValuePairs abort) — call-site LLVM
 * produces the same HT shape as array literals, so Done-when json_encode works (#26977).
 *
 * NestedJIT still registers {@see \PHPCompiler\JIT\Call\HashTableReplaceRecursiveCopy}
 * for helpers that call HashTable::replaceRecursiveCopy().
 *
 * VM SSOT: {@see \PHPCompiler\VM\HashTable::replaceRecursiveCopy()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_replace_recursive)
 */
final class ArrayReplaceRecursiveRuntime
{
    public static function replaceRecursive(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \ArgumentCountError('array_replace_recursive() expects at least 1 argument, 0 given');
        }

        $first = $args[0];
        $others = \array_slice($args, 1);
        if ([] === $others) {
            return HashTableReplaceRecursiveLlvm::replaceSingle(
                $context,
                self::argToHashtable($context, $first)
            );
        }

        return HashTableReplaceRecursiveLlvm::arrayReplaceRecursive($context, $first, ...$others);
    }

    public static function ensureLinked(Context $context): void
    {
        HashTableReplaceRecursiveLlvm::ensureOverlayFunction($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function argToHashtable(Context $context, JITVariable $arg): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return ArrayBuiltinHelper::nativeListToHashTable($context, $arg);
        }

        return ArrayBuiltinHelper::loadHashTable($context, $arg);
    }
}
