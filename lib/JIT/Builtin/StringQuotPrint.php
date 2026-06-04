<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for quoted_printable_encode/decode — LLVM from StringQuotPrintJit (#5225).
 */
final class StringQuotPrint
{
    public static function ensureLinked(Context $context): void
    {
        $resume = $context->builder->getInsertBlock();
        StringQuotPrintJit::implement($context);
        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
