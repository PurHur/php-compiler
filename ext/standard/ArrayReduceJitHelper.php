<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmActiveContextJitHelper;
use PHPCompiler\Web\Superglobals;

/**
 * array_reduce() paths for compiled JIT/AOT modules (#12646, #14979, php-in-PHP).
 *
 * SSOT shared with {@see array_reduce} VM execute()
 * php-src: ext/standard/array.c — php_array_reduce()
 * Thin standalone AOT: {@see VmActiveContextJitHelper::resolve()} → sg_vm_context (#17391 / #24117).
 *
 * NestedJIT (#24156): use {@see HashTable::iterate()} values (not iterateKeyed list-assign),
 * and avoid `new Variable()` + copyFrom wrappers around invoke args/results — those TYPE_OBJECT
 * temps do not round-trip through NestedClosureInvoke arg ABI.
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

    public static function reduceWithClosure(HashTable $ht, Variable $closure, Variable $initialOrNull): Variable
    {
        // Inline context resolve — NestedJIT mis-types `: Context` returns as int (#20816 / #24117).
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            $ctx = VmActiveContextJitHelper::resolve();
        }
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
            $carry = VmClosureInvoke::invokeVariable($closure, $carryArg, $value);
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
