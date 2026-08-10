<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for timezone_name_get() / DateTimeZone::getName() (#11746, #27307, #29733).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_name_get) / zim_DateTimeZone_getName
 *
 * Prefer compile-time materialize; else read TZ_NAME_PROPERTY at runtime (peer getOffset #29732).
 */
final class JitTimezoneNameGet
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('timezone_name_get() expects exactly 1 argument, %d given', \count($args))
            );
        }

        return self::lower($context, 'timezone_name_get', $args[0]);
    }

    /** DateTimeZone::getName($this) — same ABI as procedural (#27307 / #29733). */
    public static function invokeMethod(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('DateTimeZone::getName() expects exactly 0 arguments, %d given', max(0, \count($args) - 1))
            );
        }

        return self::lower($context, 'DateTimeZone::getName', $args[0]);
    }

    private static function lower(Context $context, string $function, JITVariable $zoneArg): Value
    {
        $zoneName = self::tryCompileTimeZoneName($context, $zoneArg);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (null !== $zoneName) {
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load($context->constantStringFromString($zoneName))
            );
            $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

            return $ptr;
        }

        // Runtime: read zone id property (thin AOT locals lose compile-time stamps) (#29733).
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $zoneObj = self::requireObjectValue($context, $zoneArg);
        $namePtr = JitTimezoneProceduralArg::readStringPropPtr(
            $context,
            $object,
            $zoneObj,
            'DateTimeZone',
            DateTimeSupport::TZ_NAME_PROPERTY
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $namePtr
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function tryCompileTimeZoneName(Context $context, JITVariable $arg): ?string
    {
        if (null !== $arg->compileTimeTimezoneName && '' !== $arg->compileTimeTimezoneName) {
            return $arg->compileTimeTimezoneName;
        }
        // Ignore New_ class-name collision on compileTimeString (#29732 / #29733).
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

        return null;
    }

    private static function requireObjectValue(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $arg->value;
        }
        if (JITVariable::TYPE_VALUE !== $arg->type) {
            throw new \LogicException(
                'DateTimeZone::getName() receiver must be an object in this compiler build (#29733)'
            );
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_OBJECT, false)
        );
        // Soft path: still readObject; empty name yields empty string rather than abort (#29733).
        unset($isObject);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }
}
