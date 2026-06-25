<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT pending HTTP header dispatch — standalone LLVM quarantine + embed PHP bridge (#9545).
 *
 * Replaces ~1.1k-line monolithic LLVM in embed builds. php-src: ext/standard/head.c
 */
final class PendingHeadersRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            PendingHeadersStandaloneLlvm::implement($context);

            return;
        }

        PendingHeadersJitBridge::implement($context);
    }
}
