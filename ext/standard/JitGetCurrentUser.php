<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_current_user() via geteuid(2) + getpwuid(3) (#6119). */
final class JitGetCurrentUser
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        $euid = $context->builder->call($context->lookupFunction('geteuid'));
        $euidI32 = $euid->typeOf() === $i32
            ? $euid
            : $context->builder->trunc($euid, $i32);
        $pw = $context->builder->call($context->lookupFunction('getpwuid'), $euidI32);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'get_current_user_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'get_current_user_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'get_current_user_done_'.$id);

        $pwNull = $context->builder->icmp(Builder::INT_EQ, $pw, $i8p->constNull());
        $context->builder->branchIf($pwNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($okBlock);
        $namePtrSlot = $context->builder->pointerCast($pw, $i8p->pointerType(0));
        $namePtr = $context->builder->load($namePtrSlot);
        $nameNull = $context->builder->icmp(Builder::INT_EQ, $namePtr, $i8p->constNull());
        $emptyBlock = BasicBlockHelper::append($context, 'get_current_user_empty_'.$id);
        $writeBlock = BasicBlockHelper::append($context, 'get_current_user_write_'.$id);
        $context->builder->branchIf($nameNull, $emptyBlock, $writeBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $firstByte = $context->builder->load($namePtr);
        $i8 = $context->getTypeFromString('int8');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $firstByte, $i8->constInt(0, false));
        $context->builder->branchIf($isEmpty, $failBlock, $writeBlock);

        $context->builder->positionAtEnd($writeBlock);
        $len = $context->builder->call($context->lookupFunction('strlen'), $namePtr);
        $lenI64 = $context->builder->zExt($len, $i64);
        $resultStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $namePtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $resultStr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        $unknownPtr = $context->builder->pointerCast($context->constantFromString('Unknown'), $i8p);
        $unknownLen = $context->builder->call($context->lookupFunction('strlen'), $unknownPtr);
        $unknownLenI64 = $context->builder->zExt($unknownLen, $i64);
        $unknownStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $unknownLenI64,
            $unknownPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $unknownStr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
