<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\JIT\Builtin\Bz2StreamRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for bzflush() via __compiler_bzflush (#22344). */
final class JitBzflush
{
    public static function invoke(Context $context, Value $handleLong): Value
    {
        Bz2StreamRuntime::ensureLinked($context);
        $ret = $context->builder->call($context->lookupFunction('__compiler_bzflush'), $handleLong);
        $i32 = $context->getTypeFromString('int32');
        $ok = $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $ok);

        return JitValueBox::pointer($context, $slot);
    }
}
