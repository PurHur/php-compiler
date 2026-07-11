<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Bz2StreamRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for bzread() via __compiler_bzread (#17301). */
final class JitBz2read
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong, Value $lengthLong): Value
    {
        Bz2StreamRuntime::ensureLinked($context);
        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_bzread'),
            $handleLong,
            $lengthLong
        );
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'bzread_fail');
        $okBlock = BasicBlockHelper::append($context, 'bzread_ok');
        $doneBlock = BasicBlockHelper::append($context, 'bzread_done');
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
