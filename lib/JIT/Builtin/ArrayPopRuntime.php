<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTablePopLastLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT for array_pop() via {@see HashTablePopLastLlvm} (#12647, #27214).
 *
 * Emits pop LLVM inline into the caller (peer {@see ArrayShiftRuntime} /
 * {@see HashTableShiftLlvm} — NestedJIT of ArrayPopJitHelper failed with undefined
 * HashTable::poplast under thin standalone AOT). Host/VM SSOT remains
 * {@see \PHPCompiler\ext\standard\ArrayPopJitHelper}.
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_pop)
 */
final class ArrayPopRuntime
{
    public static function pop(Context $context, JITVariable $array): Value
    {
        // By-ref mutator: SEPARATE_ARRAY before pop (php-src array_pop / #36397).
        $ht = HashTableHelper::separateContainerForWrite($context, $array);

        return HashTablePopLastLlvm::popLast($context, $ht);
    }

    public static function ensureLinked(Context $context): void
    {
        // Inline emission in pop() — nothing to pre-link (#27214).
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }
}
