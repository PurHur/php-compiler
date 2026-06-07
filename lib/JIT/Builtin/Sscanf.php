<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM body for __compiler_sscanf* (issue #7330). */
final class Sscanf
{
    public static function ensureLinked(Context $context): void
    {
        SscanfJit::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        SscanfJit::implement($context);
    }
}
