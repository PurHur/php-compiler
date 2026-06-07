<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for multipart POST parsing — LLVM from StringMultipartJit (#7302).
 */
final class StringMultipart
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** Standalone AOT: multipart POST helper for superglobals_refresh.c (#7302). */
    public static function ensureStandaloneBodies(Context $context): void
    {
        StringMultipartJit::ensureStandaloneBodies($context);
    }

    public static function implement(Context $context): void
    {
        $resume = null;
        try {
            $resume = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StringMultipartJit::implement($context);

        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
