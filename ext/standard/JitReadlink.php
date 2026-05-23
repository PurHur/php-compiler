<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for readlink() via libc readlink(2). */
final class JitReadlink
{
    /** Linux PATH_MAX is typically 4096; readlink(2) does not NUL-terminate on success. */
    private const BUF_SIZE = 4096;

    private static int $blockSerial = 0;

    /** @return Value __value__* (string target, or boolean false on failure) */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $i8 = $context->getTypeFromString('int8');
        $bufType = $i8->arrayType(self::BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, 'readlink_buf');
        $i8p = $context->getTypeFromString('int8*');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $sizeT = $context->getTypeFromString('size_t');
        $ret = $context->builder->call(
            $context->lookupFunction('readlink'),
            $pathPtr,
            $bufPtr,
            $sizeT->constInt(self::BUF_SIZE, false)
        );
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(Builder::INT_SLT, $ret, $i64->constInt(0, true));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'readlink_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'readlink_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'readlink_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $lenI64 = $context->builder->zExt($ret, $i64);
        $resultStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $bufPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $resultStr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
