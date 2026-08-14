<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for posix_setegid() — thin libc setegid(2) (#31066).
 *
 * Used while NestedJIT compiles {@see PosixSetegidJitHelper} `@posix_setegid` via
 * {@see \PHPCompiler\JIT\Builtin\PosixSetegidJit} (posix_setuid #31038 shape).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setegid)
 */
final class JitPosixSetegidKernel
{
    /** @return Value i1 — true when setegid(2) returns 0 */
    public static function invoke(Context $context, Value $gidI64): Value
    {
        self::ensureLibcSetegid($context);
        $i32 = $context->getTypeFromString('int32');
        $gid32 = $gidI64->typeOf() === $i32
            ? $gidI64
            : $context->builder->trunc($gidI64, $i32);
        $ret = $context->builder->call($context->lookupFunction('setegid'), $gid32);
        $retI32 = $ret->typeOf() === $i32
            ? $ret
            : $context->builder->trunc($ret, $i32);

        return $context->builder->icmp(Builder::INT_EQ, $retI32, $i32->constInt(0, false));
    }

    private static function ensureLibcSetegid(Context $context): void
    {
        try {
            $context->lookupFunction('setegid');
        } catch (\Throwable) {
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'setegid',
                $context->context->functionType($i32, false, $i32)
            );
            $context->registerFunction('setegid', $fn);
        }
    }
}
