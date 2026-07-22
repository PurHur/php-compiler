<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\JIT\Builtin\Bz2StreamRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for bzerrstr() via __compiler_bzerrstr (#22344). */
final class JitBzerrstr
{
    public static function invoke(Context $context, Value $handleLong): Value
    {
        Bz2StreamRuntime::ensureLinked($context);
        $str = $context->builder->call($context->lookupFunction('__compiler_bzerrstr'), $handleLong);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
