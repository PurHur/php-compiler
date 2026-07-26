<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Variable as JitVariable;

/**
 * Shared hashtable JIT semantics lowered into compiled modules (#10031, php-in-PHP).
 *
 * SSOT for error text and JIT type classification used by {@see \PHPCompiler\JIT\HashTableWriteLlvm}.
 * php-src: Zend/zend_hash.c — zend_hash_* string-key updates
 */
final class HashTableJitHelper
{
    /**
     * Deep copy for copy-on-write separation (Zend zend_array_dup; #18451).
     */
    public static function duplicateCopy(HashTable $ht): HashTable
    {
        return $ht->duplicate();
    }

    /**
     * Array union ($left + $right) — left keys win (Zend add_function; #3690, #10533).
     */
    public static function unionCopy(HashTable $left, HashTable $right): HashTable
    {
        return $left->unionCopy($right);
    }

    public static function unsupportedStringKeyElementTypeMessage(int $jitTypeByte): string
    {
        return 'String-key array element type not supported for JIT: '
            .JitVariable::getStringType($jitTypeByte);
    }

    public static function unsupportedIndexElementTypeMessage(int $jitTypeByte): string
    {
        return 'Array element type not supported for JIT: '
            .JitVariable::getStringType($jitTypeByte);
    }

    public static function unsupportedObjectKeyElementTypeMessage(int $jitTypeByte): string
    {
        return 'Object-key array element type not supported for JIT: '
            .JitVariable::getStringType($jitTypeByte);
    }
}
