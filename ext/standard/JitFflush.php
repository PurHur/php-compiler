<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamLifecycleRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fflush() via __compiler_fflush (issue #1189). */
final class JitFflush
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        StreamLifecycleRuntime::ensureLinked($context);
        $ret = $context->builder->call($context->lookupFunction('__compiler_fflush'), $handleLong);
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
