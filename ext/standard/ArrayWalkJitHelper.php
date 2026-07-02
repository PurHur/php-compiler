<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * array_walk() / array_walk_recursive() walks for compiled JIT/AOT modules (#14875, #14877, #14933, php-in-PHP).
 *
 * SSOT shared with {@see array_walk} VM execute() paths via {@see VmArrayWalk}
 * php-src: ext/standard/array.c — php_array_walk() / php_array_walk_recursive()
 */
final class ArrayWalkJitHelper
{
    public static function walkWithBuiltin(HashTable $table, string $builtinName): void
    {
        $fn = VmInternalCall::resolveStringCallback($builtinName);
        foreach ($table->iterateKeyed(false) as [, $value]) {
            $result = VmInternalCall::invoke($fn, $value);
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return;
            }
        }
    }

    public static function walkRecursiveWithBuiltin(HashTable $table, string $builtinName): void
    {
        $fn = VmInternalCall::resolveStringCallback($builtinName);
        if (!VmArrayWalk::walkArrayRecursiveString($table, $fn)) {
            return;
        }
    }

    public static function walkWithClosure(HashTable $table, Variable $closure, ?Variable $userdata): void
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ArrayWalkJitHelper::walkWithClosure() requires an active VM context in this compiler build'
            );
        }
        if (!VmArrayWalk::walkArrayFlatClosure(
            $ctx,
            $table,
            VmClosureCall::resolve($closure),
            $userdata
        )) {
            return;
        }
    }

    public static function walkRecursiveWithClosure(HashTable $table, Variable $closure, ?Variable $userdata): void
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ArrayWalkJitHelper::walkRecursiveWithClosure() requires an active VM context in this compiler build'
            );
        }
        if (!VmArrayWalk::walkArrayRecursiveClosure(
            $ctx,
            $table,
            VmClosureCall::resolve($closure),
            $userdata
        )) {
            return;
        }
    }
}
