<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Builtin\TimezoneOffsetRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeMethodReturn;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPLLVM\Value;

/** LLVM lowering for date_offset_get() / DateTime(Immutable)::getOffset() (#11876, #30761). */
final class JitDateOffsetGet
{
    private const TYPE_ERROR =
        'date_offset_get(): Argument #1 ($object) must be of type DateTimeInterface, %s given';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('date_offset_get() expects exactly 1 argument, %d given', \count($args))
            );
        }

        TimezoneOffsetRuntime::ensureLinked($context);

        /** @var \PHPCompiler\JIT\Builtin\Type\Object_ $object */
        $object = $context->type->object;
        $dtObj = JitTimezoneProceduralArg::requireDateTimeInterfaceObject($context, $args[0], self::TYPE_ERROR);
        $zoneName = JitTimezoneProceduralArg::readDateTimeTimezoneNameProp($context, $object, $dtObj);
        $timestamp = JitTimezoneProceduralArg::readTimestampProp($context, $object, $dtObj);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_timezone_offset_seconds'),
            $zoneName,
            $timestamp,
            $ptr
        );

        return $ptr;
    }

    /**
     * DateTime::getOffset() / DateTimeImmutable::getOffset() — $this only (#30761).
     *
     * Prefer compile-time fold when construct stamped timestamp + zone. Else read
     * DateTime layout props without class_id (thin AOT NestedJIT shifts ids — peer #29732).
     */
    public static function invokeMethod(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('DateTime::getOffset() requires $this');
        }
        if (\count($args) > 1) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    'DateTime::getOffset() expects exactly 0 arguments, %d given',
                    \count($args) - 1
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'datetime_getoffset_argc_cont');

            return JitNativeMethodReturn::longZero($context);
        }

        // Peer DateTime::format (#34614) — restore unserialize stamps onto divergent $this.
        if (null === $args[0]->compileTimeDateTimeTimestamp) {
            $last = $context->lastDateTimeUnserializeLocalName;
            $instant = null;
            if (\is_string($last) && '' !== $last && isset($context->dateTimeLocalInstants[$last])) {
                $instant = $context->dateTimeLocalInstants[$last];
            } elseif (1 === \count($context->dateTimeLocalInstants)) {
                $instant = \reset($context->dateTimeLocalInstants);
            }
            if (\is_array($instant) && isset($instant['timestamp'])) {
                $args[0]->compileTimeDateTimeTimestamp = (int) $instant['timestamp'];
                $args[0]->compileTimeDateTimeMicrosecond = (int) ($instant['microsecond'] ?? 0);
                $args[0]->compileTimeTimezoneName = $instant['timezone'] ?? null;
            }
        }

        $folded = self::tryCompileTimeOffset($args[0]);
        if (null !== $folded) {
            return JitNativeMethodReturn::long(
                $context,
                $context->getTypeFromString('int64')->constInt($folded, true)
            );
        }

        TimezoneOffsetRuntime::ensureLinked($context);

        /** @var \PHPCompiler\JIT\Builtin\Type\Object_ $object */
        $object = $context->type->object;
        $dtObj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $zoneName = JitTimezoneProceduralArg::readStringPropPtr(
            $context,
            $object,
            $dtObj,
            'DateTime',
            DateTimeSupport::TZ_PROPERTY
        );
        $timestamp = ReflectionSetup::integerPropertyAsI64(
            $context,
            $dtObj,
            'DateTime',
            DateTimeSupport::TS_PROPERTY
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_timezone_offset_seconds'),
            $zoneName,
            $timestamp,
            $ptr
        );

        return $ptr;
    }

    private static function tryCompileTimeOffset(JITVariable $dtArg): ?int
    {
        // After #32691 construct stamps live on compileTimeDateTimeTimestamp, not
        // compileTimeLong (assignToPointer must not writeLong the object). Prefer the
        // dedicated stamp; keep legacy long recovery (#33939 / peers #33911/#32691).
        $timestamp = $dtArg->compileTimeDateTimeTimestamp ?? $dtArg->compileTimeLong;
        if (null === $timestamp) {
            return null;
        }
        $tz = $dtArg->compileTimeTimezoneName;
        if (null === $tz || '' === $tz) {
            $tz = $dtArg->compileTimeString;
        }
        if (null === $tz || '' === $tz) {
            return null;
        }
        if (
            0 === \strcasecmp($tz, 'DateTime')
            || 0 === \strcasecmp($tz, 'DateTimeImmutable')
            || 0 === \strcasecmp($tz, 'DateTimeZone')
        ) {
            return null;
        }

        return VmDateTimeNative::timezoneOffsetSeconds($tz, (int) $timestamp);
    }
}
