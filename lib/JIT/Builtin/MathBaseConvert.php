<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT LLVM body for phpc_base_convert (arbitrary-base conversion; #5197).
 *
 * Lowers from {@see MathBaseConvertJit} / {@see ext/standard/VmMath.php} — no C runtime.
 */
final class MathBaseConvert
{
    public static function ensureLinked(Context $context): void
    {
        MathBaseConvertJit::implement($context);
    }

    public static function baseToZvalCall(Context $context, Value $strDataPtr, int $base): Value
    {
        self::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $longOut = BasicBlockHelper::entryAlloca($context, $i64);
        $doubleOut = BasicBlockHelper::entryAlloca($context, $double);
        $isDouble = $context->builder->call(
            $context->lookupFunction('phpc_basetozval_result'),
            $strDataPtr,
            $i64->constInt($base, false),
            $longOut,
            $doubleOut
        );
        $isDoubleFlag = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->trunc($isDouble, $i32),
            $i32->constInt(0, false)
        );

        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        $longBb = BasicBlockHelper::append($context, 'basetozval_long');
        $doubleBb = BasicBlockHelper::append($context, 'basetozval_double');
        $doneBb = BasicBlockHelper::append($context, 'basetozval_done');
        $context->builder->branchIf($isDoubleFlag, $doubleBb, $longBb);

        $context->builder->positionAtEnd($longBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $slotPtr,
            $context->builder->load($longOut)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doubleBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $context->builder->load($doubleOut)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $slotPtr;
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
