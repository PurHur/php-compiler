<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for unlink() via libc unlink(2). */
final class JitUnlink
{
    public static function invoke(Context $context, Value $pathStr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $ret = $context->builder->call(
            $context->lookupFunction('unlink'),
            $pathPtr
        );
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }
}
