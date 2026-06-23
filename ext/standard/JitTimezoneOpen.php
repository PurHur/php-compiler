<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\NativeDateInvalidTimeZoneException;
use PHPLLVM\Value;

/** LLVM lowering for timezone_open() (#4634, ext/date/php_date.c). */
final class JitTimezoneOpen
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('timezone_open() expects exactly 1 argument, %d given', \count($args))
            );
        }

        JitInternalStrictArg::rejectNullString($context, $args[0], 'timezone_open', 'timezone', 1);

        $literal = JitStringBuiltinArg::compileTimeLiteral($args[0]);
        if (null === $literal) {
            throw new \LogicException(
                'timezone_open() requires a compile-time timezone string in this compiler build (issue #4634)'
            );
        }

        $vmCtx = $context->runtime->vmContext;
        if (null === $vmCtx) {
            throw new \LogicException('timezone_open() requires VM context at JIT compile time');
        }
        try {
            DateTimeSupport::newDateTimeZoneVariable($vmCtx, $literal);
        } catch (NativeDateInvalidTimeZoneException) {
            return self::returnFalse($context);
        }

        return self::returnZoneForValidatedName($context, $literal);
    }

    private static function returnZoneForValidatedName(Context $context, string $timezone): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DateTimeZone');
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        $propSlot = $objectType->propertySlotFor($obj, 'DateTimeZone', DateTimeSupport::TZ_NAME_PROPERTY);
        $strVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString($timezone))
        );
        $objectType->propertyStore($propSlot, $strVar, JITVariable::TYPE_STRING);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );

        return $ptr;
    }

    private static function returnFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return $ptr;
    }
}
