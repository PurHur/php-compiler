<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for posix_getegid() — thin libc getegid(2) (#30986).
 *
 * Used while NestedJIT compiles {@see PosixGetegidJitHelper} `@posix_getegid` via
 * {@see \PHPCompiler\JIT\Builtin\PosixGetegidJit} (posix_getgid #30803 / getmypid #30623 shape).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getegid)
 */
final class JitPosixGetegidKernel
{
    /** @return Value int64 — effective group id */
    public static function invoke(Context $context): Value
    {
        self::ensureLibcGetegid($context);
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('getegid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    private static function ensureLibcGetegid(Context $context): void
    {
        try {
            $context->lookupFunction('getegid');
        } catch (\Throwable) {
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'getegid',
                $context->context->functionType($i32, false)
            );
            $context->registerFunction('getegid', $fn);
        }
    }
}
