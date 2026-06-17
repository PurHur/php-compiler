<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for mb_strlen() UTF-8 counting — Utf8JitHelper via StringUtf8Runtime (#158, #9246).
 */
final class StringUtf8Strlen
{
    public static function ensureLinked(Context $context): void
    {
        $resume = null;
        try {
            $resume = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        StringUtf8Runtime::ensureStrlenLinked($context);
        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        StringUtf8Runtime::ensureStrlenLinked($context);
    }
}
