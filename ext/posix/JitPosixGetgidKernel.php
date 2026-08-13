<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for posix_getgid() — thin libc getgid(2) (#30803).
 *
 * Used while NestedJIT compiles {@see PosixGetgidJitHelper} `@posix_getgid` via
 * {@see \PHPCompiler\JIT\Builtin\PosixGetgidJit} (posix_geteuid #30767 / getmypid #30623 shape).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getgid)
 */
final class JitPosixGetgidKernel
{
    /** @return Value int64 — real group id */
    public static function invoke(Context $context): Value
    {
        self::ensureLibcGetgid($context);
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('getgid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    private static function ensureLibcGetgid(Context $context): void
    {
        try {
            $context->lookupFunction('getgid');
        } catch (\Throwable) {
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'getgid',
                $context->context->functionType($i32, false)
            );
            $context->registerFunction('getgid', $fn);
        }
    }
}
