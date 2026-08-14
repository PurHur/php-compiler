<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for posix_seteuid() — thin libc seteuid(2) (#31066).
 *
 * Used while NestedJIT compiles {@see PosixSeteuidJitHelper} `@posix_seteuid` via
 * {@see \PHPCompiler\JIT\Builtin\PosixSeteuidJit} (posix_setuid #31038 shape).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_seteuid)
 */
final class JitPosixSeteuidKernel
{
    /** @return Value i1 — true when seteuid(2) returns 0 */
    public static function invoke(Context $context, Value $uidI64): Value
    {
        self::ensureLibcSeteuid($context);
        $i32 = $context->getTypeFromString('int32');
        $uid32 = $uidI64->typeOf() === $i32
            ? $uidI64
            : $context->builder->trunc($uidI64, $i32);
        $ret = $context->builder->call($context->lookupFunction('seteuid'), $uid32);
        $retI32 = $ret->typeOf() === $i32
            ? $ret
            : $context->builder->trunc($ret, $i32);

        return $context->builder->icmp(Builder::INT_EQ, $retI32, $i32->constInt(0, false));
    }

    private static function ensureLibcSeteuid(Context $context): void
    {
        try {
            $context->lookupFunction('seteuid');
        } catch (\Throwable) {
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'seteuid',
                $context->context->functionType($i32, false, $i32)
            );
            $context->registerFunction('seteuid', $fn);
        }
    }
}
