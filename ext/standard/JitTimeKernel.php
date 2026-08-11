<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for time() — thin libc time(2) (#30332).
 *
 * Used while NestedJIT compiles {@see TimeJitHelper} `@time` via
 * {@see \PHPCompiler\JIT\Builtin\StringTime} (microtime #29405 shape).
 * php-src: ext/date/php_date.c — PHP_FUNCTION(time)
 */
final class JitTimeKernel
{
    /** @return Value int64 — Unix timestamp seconds */
    public static function invoke(Context $context): Value
    {
        self::ensureLibcTime($context);
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call(
            $context->lookupFunction('time'),
            $i8p->constNull()
        );

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    private static function ensureLibcTime(Context $context): void
    {
        try {
            $context->lookupFunction('time');
        } catch (\Throwable) {
            $i8p = $context->getTypeFromString('int8*');
            $i64 = $context->getTypeFromString('int64');
            $fn = $context->module->addFunction(
                'time',
                $context->context->functionType($i64, false, $i8p)
            );
            $context->registerFunction('time', $fn);
        }
    }
}
