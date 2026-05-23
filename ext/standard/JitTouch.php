<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for touch() via __compiler_touch (libc utime). */
final class JitTouch
{
    /** @return Value i1 — true when __compiler_touch returns 1 */
    public static function invoke(Context $context, Value $pathStr, Value $mtimeLong): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_touch'),
            $pathStr,
            $mtimeLong
        );
        $one = $i32->constInt(1, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $one);
    }
}
