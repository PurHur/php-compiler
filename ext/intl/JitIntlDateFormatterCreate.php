<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for IntlDateFormatter::create() / datefmt_create() (#27361 / re-#20837).
 *
 * Allocates an IntlDateFormatter object and stores locale/styles/timezone plus the
 * resolved PHP date() format for {@see JitIntlDateFormatterFormat}.
 *
 * php-src: ext/intl/dateformat/dateformat_create.cpp — PHP_FUNCTION(datefmt_create)
 */
final class JitIntlDateFormatterCreate
{
    /** Stashed when create args are compile-time — format may CT-fold. */
    public static ?string $lastCompileTimePhpFormat = null;

    public static ?string $lastCompileTimeTimezone = null;

    public static function takeLastCompileTimePhpFormat(): ?string
    {
        $p = self::$lastCompileTimePhpFormat;
        self::$lastCompileTimePhpFormat = null;

        return $p;
    }

    public static function takeLastCompileTimeTimezone(): ?string
    {
        $tz = self::$lastCompileTimeTimezone;
        self::$lastCompileTimeTimezone = null;

        return $tz;
    }

    /**
     * @param list<JITVariable> $args static create($locale, $dateType = FULL, …) — no $this
     */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'datefmt_create() expects between 1 and 6 arguments, %d given',
                $argc
            ));
        }

        $localeStr = JitStringBuiltinArg::lowerZparamStr(
            $context,
            $args[0],
            'datefmt_create',
            0,
            'locale'
        );
        $localeLit = JitStringArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;

        $dateTypeLong = $argc >= 2
            ? self::lowerStyleLong($context, $args[1], 1, 'dateType')
            : $context->constantFromInteger(VmIntlDateFormatter::FULL);
        $timeTypeLong = $argc >= 3
            ? self::lowerStyleLong($context, $args[2], 2, 'timeType')
            : $context->constantFromInteger(VmIntlDateFormatter::FULL);

        $dateTypeCt = $argc >= 2 ? ($args[1]->compileTimeLong) : VmIntlDateFormatter::FULL;
        $timeTypeCt = $argc >= 3 ? ($args[2]->compileTimeLong) : VmIntlDateFormatter::FULL;

        $timezoneStr = null;
        $timezoneLit = null;
        if ($argc >= 4) {
            $timezoneStr = JitStringBuiltinArg::lowerZparamStr(
                $context,
                $args[3],
                'datefmt_create',
                3,
                'timezone'
            );
            $timezoneLit = JitStringArg::compileTimeLiteral($args[3]) ?? $args[3]->compileTimeString;
        } else {
            $timezoneStr = $context->builder->load($context->constantStringFromString('UTC'));
            $timezoneLit = 'UTC';
        }

        $patternLit = null;
        if ($argc >= 6) {
            $patternLit = JitStringArg::compileTimeLiteral($args[5]) ?? $args[5]->compileTimeString;
        }

        $phpFormatLit = self::resolvePhpFormatCt($localeLit, $dateTypeCt, $timeTypeCt, $patternLit);
        self::$lastCompileTimePhpFormat = $phpFormatLit;
        self::$lastCompileTimeTimezone = \is_string($timezoneLit) && '' !== $timezoneLit
            ? $timezoneLit
            : 'UTC';

        $objectType = $context->type->object;
        $classId = $objectType->lookup('IntlDateFormatter');
        $obj = $objectType->allocate($classId);

        $objectType->defineProperty($classId, IntlDateFormatterFormatJitHelper::PROP_LOCALE, JITVariable::TYPE_STRING);
        $objectType->defineProperty($classId, IntlDateFormatterFormatJitHelper::PROP_DATE_TYPE, JITVariable::TYPE_NATIVE_LONG);
        $objectType->defineProperty($classId, IntlDateFormatterFormatJitHelper::PROP_TIME_TYPE, JITVariable::TYPE_NATIVE_LONG);
        $objectType->defineProperty($classId, IntlDateFormatterFormatJitHelper::PROP_TIMEZONE, JITVariable::TYPE_STRING);
        $objectType->defineProperty($classId, IntlDateFormatterFormatJitHelper::PROP_PHP_FORMAT, JITVariable::TYPE_STRING);

        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'IntlDateFormatter', IntlDateFormatterFormatJitHelper::PROP_LOCALE),
            new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $localeStr),
            JITVariable::TYPE_STRING
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'IntlDateFormatter', IntlDateFormatterFormatJitHelper::PROP_DATE_TYPE),
            new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $dateTypeLong),
            JITVariable::TYPE_NATIVE_LONG
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'IntlDateFormatter', IntlDateFormatterFormatJitHelper::PROP_TIME_TYPE),
            new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $timeTypeLong),
            JITVariable::TYPE_NATIVE_LONG
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'IntlDateFormatter', IntlDateFormatterFormatJitHelper::PROP_TIMEZONE),
            new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $timezoneStr),
            JITVariable::TYPE_STRING
        );

        $phpFormatStr = null !== $phpFormatLit
            ? $context->builder->load($context->constantStringFromString($phpFormatLit))
            : $context->builder->load($context->constantStringFromString(''));
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'IntlDateFormatter', IntlDateFormatterFormatJitHelper::PROP_PHP_FORMAT),
            new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $phpFormatStr),
            JITVariable::TYPE_STRING
        );

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return $slot;
    }

    private static function resolvePhpFormatCt(
        ?string $localeLit,
        ?int $dateTypeCt,
        ?int $timeTypeCt,
        ?string $patternLit
    ): ?string {
        if (null !== $patternLit && '' !== $patternLit) {
            return VmIntlDateFormatter::icuPatternToPhpFormat($patternLit, true);
        }
        if (null === $localeLit || null === $dateTypeCt || null === $timeTypeCt) {
            return null;
        }
        if (!VmIntlDateFormatter::isValidUDateFormatStyle($dateTypeCt)
            || !VmIntlDateFormatter::isValidUDateFormatStyle($timeTypeCt)
            || (VmIntlDateFormatter::PATTERN === $dateTypeCt
                && VmIntlDateFormatter::PATTERN !== $timeTypeCt)
        ) {
            return null;
        }
        $icu = VmIntlDateFormatter::patternFromStyles($localeLit, $dateTypeCt, $timeTypeCt);
        if (null === $icu || '' === $icu) {
            return null;
        }

        return VmIntlDateFormatter::icuPatternToPhpFormat($icu, true);
    }

    private static function lowerStyleLong(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \TypeError(\sprintf(
            'datefmt_create(): Argument #%d ($%s) must be of type int, %s given',
            $argIndex + 1,
            $paramName,
            JITVariable::getStringType($arg->type)
        ));
    }
}
