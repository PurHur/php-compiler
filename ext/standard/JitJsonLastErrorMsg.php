<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for json_last_error_msg() via __compiler_json_last_error_msg (issue #3175). */
final class JitJsonLastErrorMsg
{
    /** @return Value (native string pointer boxed as PHP string) */
    public static function invoke(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $context->builder->call($context->lookupFunction('__compiler_json_last_error_msg'))
        );

        return $ptr;
    }
}
