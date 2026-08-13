<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for posix_getuid() — thin libc getuid(2) (#30744).
 *
 * Used while NestedJIT compiles {@see PosixGetuidJitHelper} `@posix_getuid` via
 * {@see \PHPCompiler\JIT\Builtin\PosixGetuidJit} (posix_getppid #30728 / getmypid #30623 shape).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getuid)
 */
final class JitPosixGetuidKernel
{
    /** @return Value int64 — real user id */
    public static function invoke(Context $context): Value
    {
        self::ensureLibcGetuid($context);
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('getuid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    private static function ensureLibcGetuid(Context $context): void
    {
        try {
            $context->lookupFunction('getuid');
        } catch (\Throwable) {
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'getuid',
                $context->context->functionType($i32, false)
            );
            $context->registerFunction('getuid', $fn);
        }
    }
}
