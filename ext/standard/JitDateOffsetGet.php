<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Builtin\TimezoneOffsetRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
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
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeLong(
                $context,
                $slot,
                $context->getTypeFromString('int64')->constInt(0, true)
            );

            return $slot;
        }

        $folded = self::tryCompileTimeOffset($args[0]);
        if (null !== $folded) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeLong(
                $context,
                $slot,
                $context->getTypeFromString('int64')->constInt($folded, true)
            );

            return $slot;
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
        if (null === $dtArg->compileTimeLong) {
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

        return VmDateTimeNative::timezoneOffsetSeconds($tz, (int) $dtArg->compileTimeLong);
    }
}
