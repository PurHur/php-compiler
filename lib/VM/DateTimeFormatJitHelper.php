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
        // Missing zone must NOT default to UTC (#34614): that made unserialize(named-zone)
        // format as +00:00 while getOffset() (which falls through) stayed correct.
        $fmtLit = JitStringBuiltinArg::compileTimeLiteral($formatArg) ?? $formatArg->compileTimeString;
        if (\is_string($fmtLit) && null !== $receiver->compileTimeDateTimeTimestamp) {
            $tzName = $receiver->compileTimeTimezoneName;
            if (null === $tzName || '' === $tzName) {
                // Peer getOffset (#33939) — legacy string stamp, ignore class-name collisions.
                $tzName = $receiver->compileTimeString;
            }
            if (
                \is_string($tzName)
                && '' !== $tzName
                && 0 !== \strcasecmp($tzName, 'DateTime')
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
            // Zone-aware tokens must not use the UTC-baked civil 'c'/'e'/… specs (#34614).
            // Those exist for date()/gmdate() under UTC; DateTime::format with a named
            // zone needs the #33939 fold (above) or DateTimeFormatRuntime (below).
            $zoneAwareFmt = \in_array($fmtLit, ['c', 'r', 'e', 'O', 'P', 'T'], true)
                || 'D, d M Y H:i:s O' === $fmtLit;
            if (!$zoneAwareFmt) {
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

        // Composite wall+abbr: NestedJIT formatTokensScalar aborts (#34610).
        // Civil IR for wall + NestedJIT bare T + memcpy concat (no __string__concat ABI).
        if (\is_string($fmtLit) && 'Y-m-d H:i:s T' === $fmtLit) {
            $wall = JitDate::tryFormatCivilLiteral($context, 'Y-m-d H:i:s', $timestamp, $microsecond);
            if (null !== $wall) {
                $tokT = DateTimeFormatRuntime::invoke(
                    $context,
                    $context->builder->load($context->constantStringFromString('T')),
                    $timestamp,
                    $microsecond,
                    $tzPtr
                );
                $space = $context->builder->load($context->constantStringFromString(' '));
                $mid = self::concatStringValues($context, $wall, $space);

                return self::concatStringValues($context, $mid, $tokT);
            }
        }

        // Runtime / unknown format: civil IR dispatch before NestedJIT (#34482).
        return JitDate::emitRuntimeCivilFormatDispatch(
            $context,
            $formatPtr,
            $timestamp,
            $microsecond,
            true,
            static function () use ($context, $formatPtr, $timestamp, $microsecond, $tzPtr): Value {
                return DateTimeFormatRuntime::invoke($context, $formatPtr, $timestamp, $microsecond, $tzPtr);
            }
        );
    }

    /** Memcpy concat of two `__string__*` values (peer StringSerialize::concatStr). */
    private static function concatStringValues(Context $context, Value $left, Value $right): Value
    {
        $map = $context->structFieldMap['__string__'];
        $leftSize = $context->builder->load($context->builder->structGep($left, $map['length']));
        $rightSize = $context->builder->load($context->builder->structGep($right, $map['length']));
        $size = $context->builder->add($leftSize, $rightSize);
        $result = $context->builder->call($context->lookupFunction('__string__alloc'), $size);
        $context->intrinsic->builder = $context->builder;
        $dest = $context->builder->structGep($result, $map['value']);
        $leftChar = $context->builder->structGep($left, $map['value']);
        $context->intrinsic->memcpy($dest, $leftChar, $leftSize, false);
        $dest2 = $context->builder->gep($dest, $leftSize);
        $rightChar = $context->builder->structGep($right, $map['value']);
        $context->intrinsic->memcpy($dest2, $rightChar, $rightSize, false);

        return $result;
    }
}
