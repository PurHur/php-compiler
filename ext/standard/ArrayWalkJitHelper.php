<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_walk() / array_walk_recursive() string-builtin walks for compiled JIT/AOT modules (#14875, #14877, php-in-PHP).
 *
 * SSOT shared with {@see array_walk} VM execute() internal-callback path
 * php-src: ext/standard/array.c — php_array_walk()
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
}
