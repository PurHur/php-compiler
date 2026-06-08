<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link for __compiler_trigger_error LLVM runtime (#7597). */
final class StringTriggerError
{
    public static function ensureLinked(Context $context): void
    {
        StringTriggerErrorJit::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        StringTriggerErrorJit::implement($context);
    }
}
