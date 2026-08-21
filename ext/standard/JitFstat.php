<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamFstat;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fstat() via __compiler_fstat / FstatJitHelper PHP (#10460, #3482). */
final class JitFstat
{
    /** @return Value (__value__* — array or boolean false) */
    public static function invoke(Context $context, Value $handle): Value
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        StreamFstat::ensureLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'fstat_after_link');

        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtrTy->constNull();
        $statHt = $context->builder->call(
            $context->lookupFunction('__compiler_fstat'),
            $context->builder->truncOrBitCast($handle, $context->getTypeFromString('int64'))
        );
        $failed = $context->builder->icmp(Builder::INT_EQ, $statHt, $nullHt);
        $failBlock = BasicBlockHelper::append($context, 'fstat_fail');
        $okBlock = BasicBlockHelper::append($context, 'fstat_ok');
        $doneBlock = BasicBlockHelper::append($context, 'fstat_done');
        // Single slot — avoid PHI of two allocas (thin AOT abort / verify).
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $statHt
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
