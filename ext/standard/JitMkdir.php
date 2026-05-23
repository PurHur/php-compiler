<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for mkdir() via __compiler_mkdir (libc mkdir(2), optional recursive). */
final class JitMkdir
{
    /** @return Value i1 — true when __compiler_mkdir returns 1 */
    public static function invoke(Context $context, Value $pathStr, Value $modeLong, Value $recursiveBool): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $recursiveI32 = $context->builder->zext($recursiveBool, $i32);
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_mkdir'),
            $pathStr,
            $modeLong,
            $recursiveI32
        );
        $one = $i32->constInt(1, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $one);
    }
}
