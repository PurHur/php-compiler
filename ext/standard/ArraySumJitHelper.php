<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_sum() for compiled JIT/AOT modules (#12590, php-in-PHP).
 *
 * SSOT shared with {@see array_sum} VM execute()
 * php-src: ext/standard/array.c — php_array_sum_or_product
 */
final class ArraySumJitHelper
{
    public static function sum(HashTable $ht): Variable
    {
        $sumInt = 0;
        $sumFloat = 0.0;
        $useFloat = false;
        foreach ($ht->iterate(true) as $value) {
            $coerced = VmArray::coerceArrayFoldNumericElement($value);
            if (null === $coerced) {
                continue;
            }
            [$num, $isFloat] = $coerced;
            if ($isFloat) {
                if (!$useFloat) {
                    $useFloat = true;
                    $sumFloat = (float) $sumInt + (float) $num;
                } else {
                    $sumFloat += (float) $num;
                }
                continue;
            }
            if ($useFloat) {
                $sumFloat += (float) $num;
            } else {
                $sumInt += (int) $num;
            }
        }

        $out = new Variable();
        if ($useFloat) {
            $out->float($sumFloat);
        } else {
            $out->int($sumInt);
        }

        return $out;
    }
}
