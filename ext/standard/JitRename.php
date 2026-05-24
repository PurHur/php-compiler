<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for rename() via libc rename(2). */
final class JitRename
{
    /** @return Value */
    public static function invoke(Context $context, Value $fromStr, Value $toStr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $fromPtr = $context->builder->structGep($fromStr, $map['value']);
        $toPtr = $context->builder->structGep($toStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('rename'),
            $fromPtr,
            $toPtr
        );
        $zero = $i32->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }
}
