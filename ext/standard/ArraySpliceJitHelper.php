<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_splice() for compiled JIT/AOT modules (#13643, php-in-PHP).
 *
 * SSOT: {@see HashTable::spliceInPlace()}
 * php-src: ext/standard/array.c — php_array_splice()
 */
final class ArraySpliceJitHelper
{
    public static function spliceInPlace(
        HashTable $ht,
        int $offset,
        bool $hasLength,
        int $length,
        bool $hasReplacement,
        ?HashTable $replacement
    ): HashTable {
        $removeLength = $hasLength ? $length : null;
        $repl = ($hasReplacement && null !== $replacement) ? $replacement : null;

        return $ht->spliceInPlace($offset, $removeLength, $repl);
    }
}
