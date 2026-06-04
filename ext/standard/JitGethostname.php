<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for gethostname() via libc gethostname(2) (#3465, #5952). */
final class JitGethostname
{
    /** php-src HOST_NAME_MAX; Linux gethostname(2) uses 256-byte buffer in basic_functions.c. */
    private const BUF_SIZE = 256;

    private static int $blockSerial = 0;

    public static function invoke(Context $context): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $bufType = $i8->arrayType(self::BUF_SIZE);
        $buf = $context->builder->alloca($bufType, 1, 'gethostname_buf');
        $i8p = $context->getTypeFromString('int8*');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $sizeT = $context->getTypeFromString('size_t');
        $ret = $context->builder->call(
            $context->lookupFunction('gethostname'),
            $bufPtr,
            $sizeT->constInt(self::BUF_SIZE, false)
        );
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $syscallFailed = $context->builder->icmp(Builder::INT_NE, $ret, $zero);
        $firstByte = $context->builder->load($bufPtr);
        $emptyFailed = $context->builder->icmp(Builder::INT_EQ, $firstByte, $i8->constInt(0, false));
        $failed = $context->builder->or($syscallFailed, $emptyFailed);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'gethostname_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'gethostname_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'gethostname_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call(
            $context->lookupFunction('strlen'),
            $bufPtr
        );
        $lenI64 = $context->builder->zExt($len, $i64);
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
