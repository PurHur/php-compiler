<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * shuffle() for compiled JIT/AOT modules (#12762, php-in-PHP).
 *
 * SSOT shared with {@see shuffle_} VM execute()
 * php-src: ext/standard/array.c — php_shuffle
 */
final class ShuffleJitHelper
{
    public static function shufflePacked(HashTable $ht): void
    {
        VmArray::shufflePacked($ht);
    }
}
