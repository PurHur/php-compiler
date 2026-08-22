<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPLLVM\Value;

/**
 * LLVM lowering for timezone_location_get() / DateTimeZone::getLocation() (#6041, #33727).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_location_get) / zim_DateTimeZone_getLocation
 *
 * Compile-time bake (peer getTransitions #26799). NestedJIT `__phpc_timezone_location_ht`
 * SIGSEGVs on user-script AOT (peer getdate #26900), so a missing zone id is an honest error.
 */
final class JitTimezoneLocationGet
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('timezone_location_get() expects exactly 1 argument, %d given', \count($args))
            );
        }

        return self::lower($context, 'timezone_location_get', $args[0]);
    }

    /** DateTimeZone::getLocation($this) — same ABI as procedural (#33727 / #30834). */
    public static function invokeMethod(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('DateTimeZone::getLocation() expects exactly 0 arguments, %d given', max(0, $argc - 1))
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'datetimezone_getlocation_argc_cont');
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__value__writeBool'),
                $ptr,
                $context->getTypeFromString('int32')->constInt(0, false)
            );

            return $ptr;
        }

        return self::lower($context, 'DateTimeZone::getLocation', $args[0]);
    }

    private static function lower(Context $context, string $function, JITVariable $zoneArg): Value
    {
        $zoneName = self::tryCompileTimeZoneName($context, $zoneArg);
        if (null === $zoneName) {
            throw new \LogicException(
                $function.'() requires a compile-time DateTimeZone name in this compiler build (#33727)'
            );
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $ht = TimezoneLocationJitHelper::locationHashtable($zoneName);
        if (null === $ht) {
            JitValueBox::writeBool(
                $context,
                $slot,
                $context->getTypeFromString('int1')->constInt(0, false)
            );

            return $ptr;
        }
        $htVar = HashTableHelper::variableFromVmHashTable($context, $ht);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $context->helper->loadValue($htVar)
        );

        return $ptr;
    }

    private static function tryCompileTimeZoneName(Context $context, JITVariable $arg): ?string
    {
        if (null !== $arg->compileTimeTimezoneName && '' !== $arg->compileTimeTimezoneName) {
            return $arg->compileTimeTimezoneName;
        }
        if (
            null !== $arg->compileTimeString
            && '' !== $arg->compileTimeString
            && 0 !== strcasecmp($arg->compileTimeString, 'DateTimeZone')
        ) {
            return $arg->compileTimeString;
        }

        $literal = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $literal && '' !== $literal) {
            return $literal;
        }

        if (JITVariable::TYPE_OBJECT === $arg->type) {
            /** @var ObjectBuiltin $object */
            $object = $context->type->object;
            $prop = $object->propertyFetch($arg->value, 'DateTimeZone', DateTimeSupport::TZ_NAME_PROPERTY);
            if (JITVariable::TYPE_STRING === $prop->type && null !== ($prop->compileTimeString ?? null)) {
                return $prop->compileTimeString;
            }
        }

        if (null !== $context->lastDateTimeZoneConstructedId && '' !== $context->lastDateTimeZoneConstructedId) {
            return $context->lastDateTimeZoneConstructedId;
        }

        return null;
    }
}
