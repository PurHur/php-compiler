<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** Lazy LLVM link for stripcslashes() runtime helper (#5652). */
final class StringStripcslashesRuntime
{
    public static function ensureLinked(Context $context): void
    {
        $resume = $context->builder->getInsertBlock();
        StringCslashesJit::ensureStripcslashes($context);
        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
