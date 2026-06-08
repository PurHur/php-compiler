<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link for __compiler_sprintf/printf/number_format LLVM runtime (#1492). */
final class StringFormat
{
    public static function ensureLinked(Context $context): void
    {
        StringFormatJit::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        StringFormatJit::implement($context);
    }
}
