<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for clock_gettime() — ClockGettimeJitHelper via StringClockGettimeRuntime (#11624).
 *
 * php-src: ext/standard/hrtime.c
 */
final class StringClockGettime
{
    public static function ensureLinked(Context $context): void
    {
        if (self::alreadyLinked($context)) {
            return;
        }
        self::linkNow($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function alreadyLinked(Context $context): bool
    {
        $probe = $context->module->getNamedFunction('__compiler_clock_gettime_assoc');

        return null !== $probe && $probe->countBasicBlocks() > 0;
    }

    private static function linkNow(Context $context): void
    {
        $resume = null;
        try {
            $resume = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        StringClockGettimeRuntime::ensureLinked($context);
        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
