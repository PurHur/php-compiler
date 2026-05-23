<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fclose() via __compiler_fclose (issue #1117). */
final class JitFclose
{
    /** @return Value i1 — true when fclose succeeds */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        $ret = $context->builder->call($context->lookupFunction('__compiler_fclose'), $handleLong);
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
