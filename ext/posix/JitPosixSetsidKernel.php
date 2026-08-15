<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for posix_setsid() — thin libc setsid(2) (#31235).
 *
 * Used while NestedJIT compiles {@see PosixSetsidJitHelper} `@posix_setsid` via
 * {@see \PHPCompiler\JIT\Builtin\PosixSetsidJit} (posix_getpid #30696 shape).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_setsid)
 */
final class JitPosixSetsidKernel
{
    /** @return Value int64 — session id (negative on failure) */
    public static function invoke(Context $context): Value
    {
        self::ensureLibcSetsid($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('setsid'));
        $rawI32 = $raw->typeOf() === $i32
            ? $raw
            : $context->builder->trunc($raw, $i32);

        return $context->builder->sext($rawI32, $i64);
    }

    private static function ensureLibcSetsid(Context $context): void
    {
        try {
            $context->lookupFunction('setsid');
        } catch (\Throwable) {
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'setsid',
                $context->context->functionType($i32, false)
            );
            $context->registerFunction('setsid', $fn);
        }
    }
}
