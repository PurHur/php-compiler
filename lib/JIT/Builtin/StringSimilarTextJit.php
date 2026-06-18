<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** @deprecated compat shim — logic lives in {@see StringSimilarText} (#9731). */
final class StringSimilarTextJit
{
    public static function implement(Context $context): void
    {
        StringSimilarText::implement($context);
    }
}
