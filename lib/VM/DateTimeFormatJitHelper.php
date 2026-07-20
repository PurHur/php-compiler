<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Builtin\DateTimeFormatRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPLLVM\Value;

/**
 * LLVM lowering for DateTime/DateTimeImmutable::format() (#4043, ext/date/php_datetime.c).
 */
final class DateTimeFormatJitHelper
{
    private const CLASS_DATETIME = 'DateTime';

    public static function compileFormat(
        Context $context,
        JITVariable $receiver,
        JITVariable $formatArg,
        string $function = 'DateTime::format',
        int $formatArgIndex = 0
    ): Value {
        DateTimeFormatRuntime::ensureLinked($context);
        $obj = ReflectionSetup::loadObjectFromArg($context, $receiver);
        $objectType = $context->type->object;
        $timestamp = $context->helper->loadValue(
            $objectType->propertyFetch($obj, self::CLASS_DATETIME, DateTimeSupport::TS_PROPERTY)
        );
        $microsecond = $context->helper->loadValue(
            $objectType->propertyFetch($obj, self::CLASS_DATETIME, DateTimeSupport::MICROSECOND_PROPERTY)
        );
        $tzVar = $objectType->propertyFetch($obj, self::CLASS_DATETIME, DateTimeSupport::TZ_PROPERTY);
        $tzPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $tzVar)
        );
        // Soft-null on 8.4 — Zend deprecate+coerce (#21536, reverts #20693 TypeError).
        $formatPtr = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $formatArg,
            $function,
            $formatArgIndex,
            'format'
        );

        return DateTimeFormatRuntime::invoke($context, $formatPtr, $timestamp, $microsecond, $tzPtr);
    }
}
