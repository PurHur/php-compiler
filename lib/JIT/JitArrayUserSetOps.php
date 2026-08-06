<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ArrayUserSetOpsRuntime;
use PHPLLVM\Value;

/**
 * JIT lowering for user-comparator array diff/intersect builtins (php-src ext/standard/array.c; #9155, #18515, #27228, #27243).
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmArrayUserSetOps}.
 * JIT/AOT: {@see ArrayUserSetOpsRuntime} → Value/Key/Uassoc LLVM (#27533); VM SSOT {@see \PHPCompiler\ext\standard\VmArrayUserSetOps}.
 */
final class JitArrayUserSetOps
{
    public static function arrayUdiff(
        Context $context,
        Variable $callback,
        Variable $first,
        Variable ...$others
    ): Value {
        return ArrayUserSetOpsRuntime::diffByValue($context, false, $callback, $first, ...$others);
    }

    public static function arrayUintersect(
        Context $context,
        Variable $callback,
        Variable $first,
        Variable ...$others
    ): Value {
        return ArrayUserSetOpsRuntime::diffByValue($context, true, $callback, $first, ...$others);
    }

    public static function arrayDiffUkey(
        Context $context,
        Variable $callback,
        Variable $first,
        Variable ...$others
    ): Value {
        if ([] === $others) {
            throw new \ArgumentCountError('array_diff_ukey() expects at least 3 arguments, 2 given');
        }

        return ArrayUserSetOpsRuntime::diffByKey($context, $callback, $first, ...$others);
    }

    public static function arrayIntersectUkey(
        Context $context,
        Variable $callback,
        Variable $first,
        Variable ...$others
    ): Value {
        if ([] === $others) {
            throw new \ArgumentCountError('array_intersect_ukey() expects at least 3 arguments, 2 given');
        }

        return ArrayUserSetOpsRuntime::intersectByKey($context, $callback, $first, ...$others);
    }

    public static function arrayUdiffUassoc(
        Context $context,
        Variable $valueCallback,
        Variable $keyCallback,
        Variable $first,
        Variable ...$others
    ): Value {
        if ([] === $others) {
            throw new \ArgumentCountError('array_udiff_uassoc() expects at least 4 arguments, 3 given');
        }

        return ArrayUserSetOpsRuntime::diffByKeyValue(
            $context,
            $valueCallback,
            $keyCallback,
            $first,
            ...$others
        );
    }

    public static function arrayUintersectUassoc(
        Context $context,
        Variable $valueCallback,
        Variable $keyCallback,
        Variable $first,
        Variable ...$others
    ): Value {
        if ([] === $others) {
            throw new \ArgumentCountError('array_uintersect_uassoc() expects at least 4 arguments, 3 given');
        }

        return ArrayUserSetOpsRuntime::intersectByKeyValue(
            $context,
            $valueCallback,
            $keyCallback,
            $first,
            ...$others
        );
    }
}
