<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for json_last_error() via __compiler_json_last_error (issue #1173). */
final class JitJsonLastError
{
    /** @return Value __value__* (native long error code) */
    public static function invoke(Context $context): Value
    {
        $code = $context->builder->call($context->lookupFunction('__compiler_json_last_error'));
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeLong($context, $slot, $code);

        return $ptr;
    }
}
