<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_shift() for compiled JIT/AOT modules (#12672, php-in-PHP).
 *
 * SSOT shared with {@see array_shift} VM execute()
 * php-src: ext/standard/array.c — php_array_shift()
 */
final class ArrayShiftJitHelper
{
    public static function shift(HashTable $ht): Variable
    {
        $shifted = $ht->shiftFirst();
        $out = new Variable();
        if (null === $shifted) {
            $out->null();

            return $out;
        }
        $out->copyFrom($shifted);

        return $out;
    }
}
