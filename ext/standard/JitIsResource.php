<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for is_resource() via __compiler_is_resource (#3519). */
final class JitIsResource
{
    public static function invoke(Context $context, Value $handleLong): Value
    {
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_is_resource'),
            $handleLong
        );
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
