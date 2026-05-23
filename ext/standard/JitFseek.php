<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for fseek() via __compiler_fseek (issue #1191). */
final class JitFseek
{
    /** @return Value __value__* (native long 0 or -1) */
    public static function invoke(Context $context, Value $handleLong, Value $offsetLong, Value $whenceI32): Value
    {
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_fseek'),
            $handleLong,
            $offsetLong,
            $whenceI32
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeLong($context, $slot, $ret);

        return $ptr;
    }
}
