<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for __compiler_pack (issue #5231, #6607).
 *
 * LLVM bodies from {@see StringPackJit}; semantics SSOT {@see \PHPCompiler\ext\standard\PackEngine}.
 */
final class PackJitRuntime
{
    public static function ensureLinked(Context $context): void
    {
        $resume = null;
        try {
            $resume = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StringPackJit::implement($context);

        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
