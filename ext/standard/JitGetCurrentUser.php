<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for get_current_user() via geteuid(2) + getpwuid(3) (#6119, #26941).
 *
 * Thin AOT NestedJIT of ProcessIdentityJitHelper segfaults after c:main_before_php
 * (peer getmypid #26944). VM SSOT remains script-owner via
 * {@see VmProcessIdentity::getCurrentUserForScript()}; AOT uses euid's passwd name
 * (matches php-src when the script owner is the effective uid — typical CLI).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_current_user)
 */
final class JitGetCurrentUser
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8 = $context->getTypeFromString('int8');

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
        // struct passwd { char *pw_name; ... } — first pointer field.
        $namePtrSlot = $context->builder->pointerCast($pw, $i8p->pointerType(0));
        $namePtr = $context->builder->load($namePtrSlot);
        $nameNull = $context->builder->icmp(Builder::INT_EQ, $namePtr, $i8p->constNull());
        $writeBlock = BasicBlockHelper::append($context, 'get_current_user_write_'.$id);
        $context->builder->branchIf($nameNull, $failBlock, $writeBlock);

        $context->builder->positionAtEnd($writeBlock);
        $firstByte = $context->builder->load($namePtr);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $firstByte, $i8->constInt(0, false));
        $doWrite = BasicBlockHelper::append($context, 'get_current_user_do_write_'.$id);
        $context->builder->branchIf($isEmpty, $failBlock, $doWrite);

        $context->builder->positionAtEnd($doWrite);
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
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

        // Empty string on failure — stdin/eval parity (#11755), not "Unknown".
        $context->builder->positionAtEnd($failBlock);
        $emptyPtr = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $emptyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $emptyPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $emptyStr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
