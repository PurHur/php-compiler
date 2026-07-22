<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\JIT\Builtin\Bz2StreamRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for bzerrno() via __compiler_bzerrno (#22344). */
final class JitBzerrno
{
    public static function invoke(Context $context, Value $handleLong): Value
    {
        Bz2StreamRuntime::ensureLinked($context);
        $errno = $context->builder->call($context->lookupFunction('__compiler_bzerrno'), $handleLong);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $errno);

        return JitValueBox::pointer($context, $slot);
    }
}
