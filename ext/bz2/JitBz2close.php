<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\JIT\Builtin\Bz2StreamRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for bzclose() via __compiler_bzclose (#17301). */
final class JitBz2close
{
    /** @return Value i1 true when bzclose succeeds */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        Bz2StreamRuntime::ensureLinked($context);
        $ret = $context->builder->call($context->lookupFunction('__compiler_bzclose'), $handleLong);
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
