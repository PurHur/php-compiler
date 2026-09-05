<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTablePointerLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT for key()/current()/next()/prev()/reset()/end() via {@see HashTablePointerLlvm} (#27484).
 *
 * Emits pointer LLVM inline into the caller (peer {@see ArrayPopRuntime} /
 * {@see HashTablePopLastLlvm} — NestedJIT of ArrayPointerJitHelper left
 * HashTable::pointer* unresolved under thin standalone AOT). Host/VM SSOT remains
 * {@see \PHPCompiler\ext\standard\ArrayPointerJitHelper} / {@see \PHPCompiler\VM\HashTable}.
 *
 * php-src: ext/standard/array.c — php_array_key / current / next / prev / reset / end
 * (next/prev/reset/end take array by-ref with SEPARATE_ARRAY via zend_parse `a/`).
 */
final class ArrayPointerRuntime
{
    public static function key(Context $context, JITVariable $array): Value
    {
        return HashTablePointerLlvm::key(
            $context,
            ArrayBuiltinHelper::loadHashTable($context, $array)
        );
    }

    public static function current(Context $context, JITVariable $array): Value
    {
        return HashTablePointerLlvm::current(
            $context,
            ArrayBuiltinHelper::loadHashTable($context, $array)
        );
    }

    public static function next(Context $context, JITVariable $array): Value
    {
        return HashTablePointerLlvm::next(
            $context,
            self::separateForPointerMutate($context, $array)
        );
    }

    public static function prev(Context $context, JITVariable $array): Value
    {
        return HashTablePointerLlvm::prev(
            $context,
            self::separateForPointerMutate($context, $array)
        );
    }

    public static function reset(Context $context, JITVariable $array): Value
    {
        return HashTablePointerLlvm::reset(
            $context,
            self::separateForPointerMutate($context, $array)
        );
    }

    public static function end(Context $context, JITVariable $array): Value
    {
        return HashTablePointerLlvm::end(
            $context,
            self::separateForPointerMutate($context, $array)
        );
    }

    public static function ensureLinked(Context $context): void
    {
        // Inline emission — nothing to pre-link (#27484).
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * By-ref pointer mutators: SEPARATE_ARRAY before writing internalPointer
     * (php-src zend_parse `a/` / #36397 slice 13).
     */
    private static function separateForPointerMutate(Context $context, JITVariable $array): Value
    {
        $ht = HashTableHelper::separateContainerForWrite($context, $array);
        Refcount::emitAssertExclusiveCall($context, $ht);

        return $ht;
    }
}
