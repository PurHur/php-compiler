<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_product() for compiled JIT/AOT modules (#12591, php-in-PHP).
 *
 * SSOT shared with {@see array_product} VM execute()
 * php-src: ext/standard/array.c — php_array_sum_or_product
 */
final class ArrayProductJitHelper
{
    public static function product(HashTable $ht): Variable
    {
        $prodInt = 1;
        $prodFloat = 1.0;
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
                    $prodFloat = (float) $prodInt * (float) $num;
                } else {
                    $prodFloat *= (float) $num;
                }
                continue;
            }
            $intNum = (int) $num;
            if ($useFloat) {
                $prodFloat *= (float) $intNum;
            } else {
                $prodInt *= $intNum;
            }
        }

        $out = new Variable();
        if ($useFloat) {
            $out->float($prodFloat);
        } else {
            $out->int($prodInt);
        }

        return $out;
    }
}
