<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_get_line() via __compiler_stream_get_line (issue #3738). */
final class JitStreamGetLine
{
    /** @return Value
     * (line string, or boolean false on failure/EOF) */
    public static function invoke(
        Context $context,
        Value $handleLong,
        Value $maxLengthLong,
        Value $endingStr,
    ): Value {
        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_stream_get_line'),
            $handleLong,
            $maxLengthLong,
            $endingStr
        );
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'stream_get_line_fail');
        $okBlock = BasicBlockHelper::append($context, 'stream_get_line_ok');
        $doneBlock = BasicBlockHelper::append($context, 'stream_get_line_done');
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
