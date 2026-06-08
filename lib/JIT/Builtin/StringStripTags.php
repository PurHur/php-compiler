<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for __compiler_strip_tags LLVM runtime.
 */
final class StringStripTags
{
    public static function ensureLinked(Context $context): void
    {
        StringStripTagsJit::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        StringStripTagsJit::implement($context);
    }
}
