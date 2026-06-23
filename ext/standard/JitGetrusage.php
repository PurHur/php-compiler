<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringGetrusage;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for getrusage() via GetrusageJitHelper (JIT/AOT, #5388/#9184). */
final class JitGetrusage
{
    public static function invoke(Context $context, ?JITVariable $who = null): Value
    {
        StringGetrusage::ensureLinked($context);

        $whoVal = null === $who
            ? $context->getTypeFromString('int64')->constInt(0, false)
            : self::jitWhoArg($context, $who);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_getrusage'),
            $whoVal,
            $ptr
        );

        return $ptr;
    }

    private static function jitWhoArg(Context $context, JITVariable $arg): Value
    {
        return JitGetrusageArg::lowerMode($context, $arg);
    }
}
