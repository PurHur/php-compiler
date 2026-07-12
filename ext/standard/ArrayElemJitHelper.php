<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_first() / array_last() for compiled JIT/AOT modules (#15063, php-in-PHP).
 *
 * SSOT shared with VM execute() via {@see VmArray::valueFirst()} / {@see VmArray::valueLast()}.
 * php-src: ext/standard/array.c — php_array_first, php_array_last
 */
final class ArrayElemJitHelper
{
    public static function firstArgv(HashTable $ht): Variable
    {
        VmArray::requireNonEmptyFindArray($ht, 'array_first');
        $value = VmArray::valueFirst($ht);
        $out = new Variable();
        $out->copyFrom($value);

        return $out;
    }

    public static function lastArgv(HashTable $ht): Variable
    {
        VmArray::requireNonEmptyFindArray($ht, 'array_last');
        $value = VmArray::valueLast($ht);
        $out = new Variable();
        $out->copyFrom($value);

        return $out;
    }
}
