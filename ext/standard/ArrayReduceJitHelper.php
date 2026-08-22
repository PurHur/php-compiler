<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_reduce() string-builtin NestedJIT helper for VM / optional AOT bridges (#12646, #14979, #33721).
 *
 * Thin-AOT string and Closure callbacks lower via {@see \PHPCompiler\JIT\ArrayReduceLlvm}
 * in the user module — this helper stays free of closure-invoke proxies so NestedJIT of
 * the file does not require Closure candidates (#24156 / #33721).
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
            $carry = $initialOrNull;
        }
        $empty = true;
        foreach ($ht->iterate() as $value) {
            $empty = false;
            if ($hasInitial) {
                $carryArg = $carry;
            } elseif (null === $carry) {
                $carryArg = new Variable();
                $carryArg->null();
            } else {
                $carryArg = $carry;
            }
            $carry = VmInternalCall::invoke($fn, $carryArg, $value);
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
