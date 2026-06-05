<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for strtr() — LLVM from StringStrtrJit (#5198).
 */
final class StringStrtr
{
    public static function ensureLinked(Context $context): void
    {
        $resume = $context->builder->getInsertBlock();
        StringStrtrJit::implement($context);
        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
