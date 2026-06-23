<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for getrusage() — GetrusageJitHelper via StringGetrusageRuntime (#9184).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getrusage)
 * SSOT: ext/standard/VmGetrusageNative.php, VmProcess::getrusage()
 */
final class StringGetrusage
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
        $probe = $context->module->getNamedFunction('__compiler_getrusage');

        return null !== $probe && $probe->countBasicBlocks() > 0;
    }

    private static function linkNow(Context $context): void
    {
        $resume = null;
        try {
            $resume = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        StringGetrusageRuntime::ensureLinked($context);
        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
