<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for symlink() via libc symlinkat(2) (issue #3227; avoids LLVM symbol name "symlink"). */
final class JitSymlink
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
        $ret = $context->builder->call(
            $context->lookupFunction('symlinkat'),
            $targetPtr,
            $atFdcwd,
            $linkPtr
        );
        $zero = $i32->constInt(0, false);
        $ok = $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
        // InternalArgInfo lists int return; box as 0/1 long for assign lowering.
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->zext($ok, $i64);
    }
}
