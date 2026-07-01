<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\GzStreamRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for gzclose() via __compiler_gzclose (#6168). */
final class JitGzclose
{
    /** @return Value i1 true when gzclose succeeds */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        GzStreamRuntime::ensureLinked($context);
        $ret = $context->builder->call($context->lookupFunction('__compiler_gzclose'), $handleLong);
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
