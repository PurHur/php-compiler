<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_replace_key() for compiled JIT/AOT modules (#12488, php-in-PHP).
 *
 * SSOT: {@see HashTable::replaceKeyCopy()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_replace_key) (PHP 8.4+)
 */
final class ArrayReplaceKeyJitHelper
{
    public static function replaceKeyCopy(HashTable $base, HashTable $replacements): HashTable
    {
        return $base->replaceKeyCopy($replacements);
    }
}
