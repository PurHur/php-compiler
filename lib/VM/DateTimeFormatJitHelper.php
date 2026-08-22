<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\JitDate;
use PHPCompiler\ext\standard\VmDateTimeNative;
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
        $i64 = $context->getTypeFromString('int64');
        $obj = null;
        $objectType = $context->type->object;
        $compileTimeMicro = null !== $receiver->compileTimeDateTimeMicrosecond
            ? (int) $receiver->compileTimeDateTimeMicrosecond
            : null;
        if (null !== $receiver->compileTimeDateTimeTimestamp) {
            $timestamp = $i64->constInt($receiver->compileTimeDateTimeTimestamp, true);
        } else {
            $obj = ReflectionSetup::loadObjectFromArg($context, $receiver);
            $timestamp = $context->helper->loadValue(
                $objectType->propertyFetch($obj, self::CLASS_DATETIME, DateTimeSupport::TS_PROPERTY)
            );
        }

        // Thin AOT under PROFILE=8.4: NestedJIT formatStateArgv civil digests segfault
        // (#27192). Prefer full compile-time fold (zone-aware) when construct stamped the
        // instant — UTC civil IR alone miscompiles named zones and T/e/O/P SIGABRT (#33939).
        $fmtLit = JitStringBuiltinArg::compileTimeLiteral($formatArg) ?? $formatArg->compileTimeString;
        if (\is_string($fmtLit) && null !== $receiver->compileTimeDateTimeTimestamp) {
            $tzName = $receiver->compileTimeTimezoneName;
            if (null === $tzName || '' === $tzName) {
                $tzName = 'UTC';
            }
            if (
                0 !== \strcasecmp($tzName, 'DateTime')
                && 0 !== \strcasecmp($tzName, 'DateTimeImmutable')
                && 0 !== \strcasecmp($tzName, 'DateTimeZone')
            ) {
                $folded = VmDateTimeNative::format(
                    (int) $receiver->compileTimeDateTimeTimestamp,
                    null !== $compileTimeMicro ? $compileTimeMicro : 0,
                    $tzName,
                    $fmtLit
                );

                return $context->builder->load($context->constantStringFromString($folded));
            }
        }
        if (\is_string($fmtLit)) {
            $needsMicro = ('u' === $fmtLit || str_contains($fmtLit, '.u') || str_contains($fmtLit, 'u'));
            // Bare 'U' is unix seconds — not microseconds.
            if ('U' === $fmtLit) {
                $needsMicro = false;
            }
            $microForCivil = null;
            if ($needsMicro) {
                if (null !== $compileTimeMicro) {
                    $microForCivil = $i64->constInt($compileTimeMicro, false);
                } else {
                    if (null === $obj) {
                        $obj = ReflectionSetup::loadObjectFromArg($context, $receiver);
                    }
                    $microForCivil = $context->helper->loadValue(
                        $objectType->propertyFetch(
                            $obj,
                            self::CLASS_DATETIME,
                            DateTimeSupport::MICROSECOND_PROPERTY
                        )
                    );
                }
            }
            $civil = JitDate::tryFormatCivilLiteral($context, $fmtLit, $timestamp, $microForCivil);
            if (null !== $civil) {
                return $civil;
            }
        }

        DateTimeFormatRuntime::ensureLinked($context);
        if (null === $obj && null !== $receiver->compileTimeTimezoneName) {
            // Prefer dedicated micro stamp (#33915 / #33922); NestedJIT on `u` SIGABRTs.
            $microsecond = $i64->constInt(
                null !== $compileTimeMicro ? $compileTimeMicro : 0,
                false
            );
            $tzPtr = $context->builder->load(
                $context->constantStringFromString($receiver->compileTimeTimezoneName)
            );
        } else {
            if (null === $obj) {
                $obj = ReflectionSetup::loadObjectFromArg($context, $receiver);
            }
            $microsecond = $context->helper->loadValue(
                $objectType->propertyFetch($obj, self::CLASS_DATETIME, DateTimeSupport::MICROSECOND_PROPERTY)
            );
            $tzVar = $objectType->propertyFetch($obj, self::CLASS_DATETIME, DateTimeSupport::TZ_PROPERTY);
            $tzPtr = $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $tzVar)
            );
        }
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
