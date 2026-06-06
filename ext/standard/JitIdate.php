<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringIdate;
use PHPCompiler\JIT\Context;
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
            : self::jitTimestampArg($context, $timestamp);

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

    private static function jitTimestampArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $arg->value
            );
        }

        throw new \LogicException('idate() timestamp must be an integer or null in this compiler build');
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $arg->value
            );
        }

        throw new \LogicException('idate() format must be a string in this compiler build');
    }
}
