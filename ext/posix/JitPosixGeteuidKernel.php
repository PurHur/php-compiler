<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for posix_geteuid() — thin libc geteuid(2) (#30767).
 *
 * Used while NestedJIT compiles {@see PosixGeteuidJitHelper} `@posix_geteuid` via
 * {@see \PHPCompiler\JIT\Builtin\PosixGeteuidJit} (posix_getuid #30744 / getmypid #30623 shape).
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_geteuid)
 */
final class JitPosixGeteuidKernel
{
    /** @return Value int64 — effective user id */
    public static function invoke(Context $context): Value
    {
        self::ensureLibcGeteuid($context);
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('geteuid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    private static function ensureLibcGeteuid(Context $context): void
    {
        try {
            $context->lookupFunction('geteuid');
        } catch (\Throwable) {
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'geteuid',
                $context->context->functionType($i32, false)
            );
            $context->registerFunction('geteuid', $fn);
        }
    }
}
