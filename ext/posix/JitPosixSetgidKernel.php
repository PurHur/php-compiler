<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for posix_setgid() — thin libc setgid(2) (#31066).
 *
 * Used while NestedJIT compiles {@see PosixSetgidJitHelper} `@posix_setgid` via
 * {@see \PHPCompiler\JIT\Builtin\PosixSetgidJit} (posix_setuid #31038 shape).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setgid)
 */
final class JitPosixSetgidKernel
{
    /** @return Value i1 — true when setgid(2) returns 0 */
    public static function invoke(Context $context, Value $gidI64): Value
    {
        self::ensureLibcSetgid($context);
        $i32 = $context->getTypeFromString('int32');
        $gid32 = $gidI64->typeOf() === $i32
            ? $gidI64
            : $context->builder->trunc($gidI64, $i32);
        $ret = $context->builder->call($context->lookupFunction('setgid'), $gid32);
        $retI32 = $ret->typeOf() === $i32
            ? $ret
            : $context->builder->trunc($ret, $i32);

        return $context->builder->icmp(Builder::INT_EQ, $retI32, $i32->constInt(0, false));
    }

    private static function ensureLibcSetgid(Context $context): void
    {
        try {
            $context->lookupFunction('setgid');
        } catch (\Throwable) {
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'setgid',
                $context->context->functionType($i32, false, $i32)
            );
            $context->registerFunction('setgid', $fn);
        }
    }
}
