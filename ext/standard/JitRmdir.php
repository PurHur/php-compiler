<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for rmdir() via libc rmdir(2). */
final class JitRmdir
{
    private static int $blockSerial = 0;

    /** @return Value */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        StringTriggerErrorJit::implement($context);
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('rmdir'),
            $pathPtr
        );
        $zero = $i32->constInt(0, false);
        $failed = $context->builder->icmp(Builder::INT_NE, $ret, $zero);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'rmdir_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'rmdir_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'rmdir_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitBuiltinWarning::emitPathNoSuchFile($context, $pathStr, 'rmdir');
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }
}
