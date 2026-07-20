<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringIdate;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for idate() via StringIdate (__compiler_idate, #6830). */
final class JitIdate
{
    public static function invoke(Context $context, JITVariable $format, ?JITVariable $timestamp = null): Value
    {
        StringIdate::ensureLinked($context);

        $formatPtr = self::jitStringArg($context, $format);
        $ts = null === $timestamp
            ? JitDate::time($context)
            : JitDateTimestampArg::lowerNullable(
                $context,
                $timestamp,
                'idate',
                2,
                'timestamp',
                JitDate::time($context)
            );

        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_idate'),
            $formatPtr,
            $ts
        );

        $i64 = $context->getTypeFromString('int64');
        $negOne = $i64->constInt(-1, true);
        $negTwo = $i64->constInt(-2, true);
        $isError = $context->builder->or_(
            $context->builder->icmp(Builder::INT_EQ, $raw, $negOne),
            $context->builder->icmp(Builder::INT_EQ, $raw, $negTwo)
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseBb = BasicBlockHelper::append($context, 'idate_false');
        $intBb = BasicBlockHelper::append($context, 'idate_int');
        $mergeBb = BasicBlockHelper::append($context, 'idate_merge');
        $context->builder->branchIf($isError, $falseBb, $intBb);

        $context->builder->positionAtEnd($falseBb);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($intBb);
        JitValueBox::writeLong($context, $slot, $raw);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);

        return $ptr;
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        // Soft-null on 8.4 — Zend deprecate+coerce (#21491, reverts #20227 TypeError).
        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'idate',
            0,
            'format'
        );
    }
}
