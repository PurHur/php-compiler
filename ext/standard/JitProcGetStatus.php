<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitResourceArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for proc_get_status() via __compiler_proc_get_status (#3740). */
final class JitProcGetStatus
{
    public static function invoke(Context $context, JITVariable $procArg): Value
    {
        JitResourceArg::rejectEnumCaseOperand($context, $procArg, 'proc_get_status', 0, 'process');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $procArg, 'proc_get_status() process'),
            $context->getTypeFromString('int64')
        );
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_proc_get_status'),
            $handle
        );

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $raw, $htPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'proc_get_status_fail');
        $okBlock = BasicBlockHelper::append($context, 'proc_get_status_ok');
        $doneBlock = BasicBlockHelper::append($context, 'proc_get_status_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $raw
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
