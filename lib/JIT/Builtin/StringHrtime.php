<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for hrtime() — HrtimeJitHelper via StringHrtimeRuntime (#9182).
 *
 * php-src: ext/standard/hrtime.c
 * SSOT: ext/standard/VmHrtimeNative.php
 */
final class StringHrtime
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
        $probe = $context->module->getNamedFunction('__compiler_hrtime_ns');

        return null !== $probe && $probe->countBasicBlocks() > 0;
    }

    private static function linkNow(Context $context): void
    {
        $resume = null;
        try {
            $resume = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        StringHrtimeRuntime::ensureLinked($context);
        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
