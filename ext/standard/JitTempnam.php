<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for tempnam() via __compiler_tempnam (issue #1201). */
final class JitTempnam
{
    /** @return Value __value__* (path string, or boolean false on failure) */
    public static function invoke(Context $context, Value $dirStr, Value $prefixStr): Value
    {
        $path = $context->builder->call(
            $context->lookupFunction('__compiler_tempnam'),
            $dirStr,
            $prefixStr
        );
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'tempnam_fail');
        $okBlock = BasicBlockHelper::append($context, 'tempnam_ok');
        $doneBlock = BasicBlockHelper::append($context, 'tempnam_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $path
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
