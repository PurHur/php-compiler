<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** Lazy link for stripcslashes() runtime helper — delegates to StringCslashes (#5652, #9578). */
final class StringStripcslashesRuntime
{
    public static function ensureLinked(Context $context): void
    {
        $resume = $context->builder->getInsertBlock();
        StringCslashes::ensureStripcslashes($context);
        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
