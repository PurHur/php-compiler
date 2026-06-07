<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamSync;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fsync() via __compiler_fsync (issue #6062). */
final class JitFsync
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        StreamSync::ensureLinked($context);
        $ret = $context->builder->call($context->lookupFunction('__compiler_fsync'), $handleLong);
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
