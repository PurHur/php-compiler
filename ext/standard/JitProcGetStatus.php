<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ProcessOpen;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
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
        // STANDALONE/EMBED AOT skips eager ProcessOpen link (#12910); ensure before lookup (#23722).
        ProcessOpen::ensureLinked($context);
        JitResourceArg::rejectEnumCaseOperand($context, $procArg, 'proc_get_status', 0, 'process');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $procArg, 'proc_get_status() process'),
            $context->getTypeFromString('int64')
        );
        $i32 = $context->getTypeFromString('int32');
        $zeroI32 = $i32->constInt(0, false);
        $isProcess = $context->builder->call(
            $context->lookupFunction('__compiler_is_process_resource'),
            $context->builder->trunc($handle, $i32)
        );
        $isActive = $context->builder->icmp(Builder::INT_NE, $isProcess, $zeroI32);
        $validBlock = BasicBlockHelper::append($context, 'proc_get_status_valid');
        $invalidBlock = BasicBlockHelper::append($context, 'proc_get_status_invalid');
        $context->builder->branchIf($isActive, $validBlock, $invalidBlock);

        $context->builder->positionAtEnd($invalidBlock);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            'proc_get_status(): supplied resource is not a valid process resource'
        );
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($validBlock);
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
