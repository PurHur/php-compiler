<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fgetc() via __compiler_fgetc (issue #1195). */
final class JitFgetc
{
    /** @return Value
     * (single-character string or boolean false at EOF / on failure) */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_fgetc'),
            $handleLong
        );
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'fgetc_fail');
        $okBlock = BasicBlockHelper::append($context, 'fgetc_ok');
        $doneBlock = BasicBlockHelper::append($context, 'fgetc_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $contents
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
