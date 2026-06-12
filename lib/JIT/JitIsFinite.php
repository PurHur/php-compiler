<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/** glibc exports isnan/isinf but not isfinite (header macro); compose for AOT link. */
final class JitIsFinite
{
    public static function lower(Context $context, Value $doubleVal): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $nan = $context->builder->call($context->lookupFunction('isnan'), $doubleVal);
        $inf = $context->builder->call($context->lookupFunction('isinf'), $doubleVal);
        $notNan = $context->builder->icmp(Builder::INT_EQ, $nan, $zero);
        $notInf = $context->builder->icmp(Builder::INT_EQ, $inf, $zero);

        return $context->builder->and($notNan, $notInf);
    }
}
