<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\JIT\Builtin\Bz2StreamRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for bzerror() via __compiler_bzerror (#22344). */
final class JitBzerror
{
    public static function invoke(Context $context, Value $handleLong): Value
    {
        Bz2StreamRuntime::ensureLinked($context);
        $ht = $context->builder->call($context->lookupFunction('__compiler_bzerror'), $handleLong);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);

        return $ptr;
    }
}
