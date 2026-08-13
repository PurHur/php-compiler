<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for posix_getppid() — thin libc getppid(2) (#30728).
 *
 * Used while NestedJIT compiles {@see PosixGetppidJitHelper} `@posix_getppid` via
 * {@see \PHPCompiler\JIT\Builtin\PosixGetppidJit} (posix_getpid #30696 / getmypid #30623 shape).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getppid)
 */
final class JitPosixGetppidKernel
{
    /** @return Value int64 — parent process id */
    public static function invoke(Context $context): Value
    {
        self::ensureLibcGetppid($context);
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('getppid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    private static function ensureLibcGetppid(Context $context): void
    {
        try {
            $context->lookupFunction('getppid');
        } catch (\Throwable) {
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'getppid',
                $context->context->functionType($i32, false)
            );
            $context->registerFunction('getppid', $fn);
        }
    }
}
