<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for posix_setuid() — thin libc setuid(2) (#31038).
 *
 * Used while NestedJIT compiles {@see PosixSetuidJitHelper} `@posix_setuid` via
 * {@see \PHPCompiler\JIT\Builtin\PosixSetuidJit} (posix_getegid #30986 / proc_nice #30615 shape).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setuid)
 */
final class JitPosixSetuidKernel
{
    /** @return Value i1 — true when setuid(2) returns 0 */
    public static function invoke(Context $context, Value $uidI64): Value
    {
        self::ensureLibcSetuid($context);
        $i32 = $context->getTypeFromString('int32');
        $uid32 = $uidI64->typeOf() === $i32
            ? $uidI64
            : $context->builder->trunc($uidI64, $i32);
        $ret = $context->builder->call($context->lookupFunction('setuid'), $uid32);
        $retI32 = $ret->typeOf() === $i32
            ? $ret
            : $context->builder->trunc($ret, $i32);

        return $context->builder->icmp(Builder::INT_EQ, $retI32, $i32->constInt(0, false));
    }

    private static function ensureLibcSetuid(Context $context): void
    {
        try {
            $context->lookupFunction('setuid');
        } catch (\Throwable) {
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'setuid',
                $context->context->functionType($i32, false, $i32)
            );
            $context->registerFunction('setuid', $fn);
        }
    }
}
