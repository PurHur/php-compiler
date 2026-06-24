<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for __compiler_sscanf* (issue #7330, #9134).
 *
 * Two-arg array return uses {@see SscanfJitHelper} PHP; standalone keeps {@see SscanfJit} LLVM.
 */
final class Sscanf
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        SscanfJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            SscanfJit::implement($context);

            return;
        }

        StringSscanfArray::implement($context);
        SscanfJit::implement($context);
    }
}
