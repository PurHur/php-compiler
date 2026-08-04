<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
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
            ArrayBuiltinHelper::loadHashTable($context, $array)
        );
    }

    public static function prev(Context $context, JITVariable $array): Value
    {
        return HashTablePointerLlvm::prev(
            $context,
            ArrayBuiltinHelper::loadHashTable($context, $array)
        );
    }

    public static function reset(Context $context, JITVariable $array): Value
    {
        return HashTablePointerLlvm::reset(
            $context,
            ArrayBuiltinHelper::loadHashTable($context, $array)
        );
    }

    public static function end(Context $context, JITVariable $array): Value
    {
        return HashTablePointerLlvm::end(
            $context,
            ArrayBuiltinHelper::loadHashTable($context, $array)
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
}
