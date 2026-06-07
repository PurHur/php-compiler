<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for convert_uuencode/convert_uudecode — LLVM from StringConvertUuJit (#6307).
 */
final class StringConvertUu
{
    public static function ensureLinked(Context $context): void
    {
        $resume = $context->builder->getInsertBlock();
        StringConvertUuJit::implement($context);
        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
