<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for posix_times() — PosixTimesJitHelper via PosixTimesRuntime (#9218).
 *
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_times)
 */
final class StringPosixTimes
{
    public static function ensureLinked(Context $context): void
    {
        if (self::alreadyLinked($context)) {
            return;
        }
        self::linkNow($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function alreadyLinked(Context $context): bool
    {
        $probe = $context->module->getNamedFunction('__compiler_posix_times');

        return null !== $probe && $probe->countBasicBlocks() > 0;
    }

    private static function linkNow(Context $context): void
    {
        $resume = null;
        try {
            $resume = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        PosixTimesRuntime::ensureLinked($context);
        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
