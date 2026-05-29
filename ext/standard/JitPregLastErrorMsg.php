<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringPregMatch;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for preg_last_error_msg() via __compiler_preg_last_error_msg (issue #3110). */
final class JitPregLastErrorMsg
{
    /** @return Value (native string pointer boxed as PHP string) */
    public static function invoke(Context $context): Value
    {
        StringPregMatch::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $context->builder->call($context->lookupFunction('__compiler_preg_last_error_msg'))
        );

        return $ptr;
    }
}
