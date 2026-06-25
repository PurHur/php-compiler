<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for opendir() via __compiler_opendir (issue #3235). */
final class JitOpendir
{
    /** @return Value (dir handle long, or boolean false on failure) */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        StringTriggerErrorJit::implement($context);
        $handle = $context->builder->call(
            $context->lookupFunction('__compiler_opendir'),
            $pathStr
        );
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(Builder::INT_SLT, $handle, $i64->constInt(0, false));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'opendir_fail');
        $okBlock = BasicBlockHelper::append($context, 'opendir_ok');
        $doneBlock = BasicBlockHelper::append($context, 'opendir_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitBuiltinWarning::emitPathOpenFailed($context, $pathStr, 'opendir');
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $handle);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
