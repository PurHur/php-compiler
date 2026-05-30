<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for flock() via __compiler_flock (issue #3141). */
final class JitFlock
{
    /** @return Value true when flock succeeds */
    public static function invoke(Context $context, Value $handleLong, Value $operationLong): Value
    {
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_flock'),
            $handleLong,
            $operationLong
        );
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
