<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_reduce() string-builtin path for compiled JIT/AOT modules (#12646, php-in-PHP).
 *
 * SSOT shared with {@see array_reduce} VM execute()
 * php-src: ext/standard/array.c — php_array_reduce()
 */
final class ArrayReduceJitHelper
{
    public static function reduceWithBuiltin(HashTable $ht, string $builtinName, Variable $initialOrNull): Variable
    {
        $fn = VmInternalCall::resolveStringCallback($builtinName);
        $hasInitial = Variable::TYPE_NULL !== $initialOrNull->type;
        $carry = null;
        if ($hasInitial) {
            $carry = new Variable();
            $carry->copyFrom($initialOrNull);
        }
        $empty = true;
        foreach ($ht->iterateKeyed(true) as [, $value]) {
            $empty = false;
            $item = new Variable();
            $item->copyFrom($value);
            if ($hasInitial) {
                $carryArg = $carry;
            } elseif (null === $carry) {
                $carryArg = new Variable();
                $carryArg->null();
            } else {
                $carryArg = $carry;
            }
            $carry = VmInternalCall::invoke($fn, $carryArg, $item);
        }
        $out = new Variable();
        if ($empty) {
            if ($hasInitial) {
                $out->copyFrom($initialOrNull);
            } else {
                $out->null();
            }

            return $out;
        }
        $out->copyFrom($carry);

        return $out;
    }
}
