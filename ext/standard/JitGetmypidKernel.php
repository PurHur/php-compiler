<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for getmypid() — thin libc getpid(2) (#30623).
 *
 * Used while NestedJIT compiles {@see GetmypidJitHelper} `@getmypid` via
 * {@see \PHPCompiler\JIT\Builtin\ProcessIdentityJit} (time #30332 / JitTimeKernel shape).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getmypid)
 */
final class JitGetmypidKernel
{
    /** @return Value int64 — process id */
    public static function invoke(Context $context): Value
    {
        self::ensureLibcGetpid($context);
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('getpid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    private static function ensureLibcGetpid(Context $context): void
    {
        try {
            $context->lookupFunction('getpid');
        } catch (\Throwable) {
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'getpid',
                $context->context->functionType($i32, false)
            );
            $context->registerFunction('getpid', $fn);
        }
    }
}
