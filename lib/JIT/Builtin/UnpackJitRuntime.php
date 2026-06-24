<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for __compiler_unpack via UnpackJitHelper PHP (#9543).
 *
 * Replaces LLVM {@see StringUnpackJit} for JIT modules; standalone keeps StringUnpackJit.
 * SSOT: {@see \PHPCompiler\ext\standard\UnpackEngine}.
 */
final class UnpackJitRuntime
{
    public static function ensureLinked(Context $context): void
    {
        $resume = null;
        try {
            $resume = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StringUnpack::ensureLinked($context);

        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
