<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for link() via libc linkat(2) (issue #3589; avoids LLVM symbol name "link"). */
final class JitLink
{
    /** Linux AT_FDCWD — same as {@see fcntl.h}. */
    private const AT_FDCWD = -100;

    private static int $blockSerial = 0;

    /** @return Value */
    public static function invoke(Context $context, Value $targetStr, Value $linkStr): Value
    {
        StringTriggerErrorJit::implement($context);
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
        $failed = $context->builder->icmp(Builder::INT_NE, $ret, $zero);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'link_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'link_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'link_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitBuiltinWarning::emit($context, 'link(): No such file or directory');
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $ok = $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->zext($ok, $i64);
    }
}
