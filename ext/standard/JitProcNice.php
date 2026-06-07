<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for proc_nice() via libc nice(3) (#5181, #6871). */
final class JitProcNice
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context, JITVariable $priorityArg): Value
    {
        self::ensureLibcNice($context);
        $priority = JitLongArg::lower($context, $priorityArg, 'proc_nice() priority');
        $i32 = $context->getTypeFromString('int32');
        $zeroI32 = $i32->constInt(0, false);
        $minusOne = $i32->constInt(-1, false);

        $priorityI32 = $priority->typeOf() === $i32
            ? $priority
            : $context->builder->trunc($priority, $i32);

        $errnoPtr = $context->builder->call($context->lookupFunction('__errno_location'));
        $context->builder->store($zeroI32, $errnoPtr);

        $ret = $context->builder->call(
            $context->lookupFunction('nice'),
            $priorityI32
        );
        $retI32 = $ret->typeOf() === $i32 ? $ret : $context->builder->trunc($ret, $i32);
        $errnoVal = $context->builder->load($errnoPtr);

        $id = (string) (++self::$blockSerial);
        $checkErrnoBlock = BasicBlockHelper::append($context, 'proc_nice_check_errno_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'proc_nice_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'proc_nice_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'proc_nice_done_'.$id);

        $isMinusOne = $context->builder->icmp(Builder::INT_EQ, $retI32, $minusOne);
        $context->builder->branchIf($isMinusOne, $checkErrnoBlock, $okBlock);

        $context->builder->positionAtEnd($checkErrnoBlock);
        $hasErrno = $context->builder->icmp(Builder::INT_NE, $errnoVal, $zeroI32);
        $context->builder->branchIf($hasErrno, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $isTrue = $context->builder->phi($i32);
        $isTrue->addIncoming($zeroI32, $failBlock);
        $isTrue->addIncoming($i32->constInt(1, false), $okBlock);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->builder->icmp(Builder::INT_NE, $isTrue, $zeroI32)
        );

        return $ptr;
    }

    private static function ensureLibcNice(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i32Ptr = $i32->pointerType(0);

        try {
            $context->lookupFunction('nice');
        } catch (\Throwable $e) {
            $ft = $context->context->functionType($i32, false, $i32);
            $fn = $context->module->addFunction('nice', $ft);
            $context->registerFunction('nice', $fn);
        }
        try {
            $context->lookupFunction('__errno_location');
        } catch (\Throwable $e) {
            $ft = $context->context->functionType($i32Ptr, false);
            $fn = $context->module->addFunction('__errno_location', $ft);
            $context->registerFunction('__errno_location', $fn);
        }
    }
}
