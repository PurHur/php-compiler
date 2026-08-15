<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for posix_setpgid() — thin libc setpgid(2) (#31235).
 *
 * Used while NestedJIT compiles {@see PosixSetpgidJitHelper} `@posix_setpgid` via
 * {@see \PHPCompiler\JIT\Builtin\PosixSetpgidJit} (posix_setuid #31038 shape).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setpgid)
 */
final class JitPosixSetpgidKernel
{
    /** @return Value i1 — true when setpgid(2) returns 0 */
    public static function invoke(Context $context, Value $pidI64, Value $pgidI64): Value
    {
        self::ensureLibcSetpgid($context);
        $i32 = $context->getTypeFromString('int32');
        $pid32 = $pidI64->typeOf() === $i32
            ? $pidI64
            : $context->builder->trunc($pidI64, $i32);
        $pgid32 = $pgidI64->typeOf() === $i32
            ? $pgidI64
            : $context->builder->trunc($pgidI64, $i32);
        $ret = $context->builder->call($context->lookupFunction('setpgid'), $pid32, $pgid32);
        $retI32 = $ret->typeOf() === $i32
            ? $ret
            : $context->builder->trunc($ret, $i32);

        return $context->builder->icmp(Builder::INT_EQ, $retI32, $i32->constInt(0, false));
    }

    private static function ensureLibcSetpgid(Context $context): void
    {
        try {
            $context->lookupFunction('setpgid');
        } catch (\Throwable) {
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'setpgid',
                $context->context->functionType($i32, false, $i32, $i32)
            );
            $context->registerFunction('setpgid', $fn);
        }
    }
}
