<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for link() via libc linkat(2) (issue #3589; avoids LLVM symbol name "link"). */
final class JitLink
{
    /** Linux AT_FDCWD — same as {@see fcntl.h}. */
    private const AT_FDCWD = -100;

    /** @return Value */
    public static function invoke(Context $context, Value $targetStr, Value $linkStr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $targetPtr = $context->builder->structGep($targetStr, $map['value']);
        $linkPtr = $context->builder->structGep($linkStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $atFdcwd = $i32->constInt(self::AT_FDCWD, true);
        $flags = $i32->constInt(0, false);
        $ret = $context->builder->call(
            $context->lookupFunction('linkat'),
            $atFdcwd,
            $targetPtr,
            $atFdcwd,
            $linkPtr,
            $flags
        );
        $zero = $i32->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }
}
