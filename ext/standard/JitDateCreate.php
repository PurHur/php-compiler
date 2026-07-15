<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\NativeDateInvalidTimeZoneException;
use PHPLLVM\Value;

/** LLVM lowering for date_create() / date_create_immutable() (#4124, ext/date/php_date.c). */
final class JitDateCreate
{
    public static function invoke(Context $context, bool $immutable, JITVariable ...$args): Value
    {
        $argc = \count($args);
        $function = $immutable ? 'date_create_immutable' : 'date_create';
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at most 2 arguments, %d given',
                $function,
                $argc
            ));
        }

        $timeLit = 'now';
        if ($argc >= 1) {
            if (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant) {
                $timeLit = VmDateTimeCreateArg::jitNullDatetimeLiteral($context, $args[0], $function, 0, 'datetime');
            } else {
                $lit = self::compileTimeStringArg($args[0]);
                if (null !== $lit) {
                    $timeLit = $lit;
                } else {
                    throw new \LogicException(
                        $function.'() requires compile-time string operands in this compiler build (issue #4124)'
                    );
                }
            }
        }
        if ('' === $timeLit) {
            $timeLit = 'now';
        }
        $tzLit = $argc >= 2 ? self::compileTimeTimezoneName($context, $args[1], $function) : null;
        if (null !== $timeLit && (2 !== $argc || null !== $tzLit)) {
            $parsed = self::parseAtCompileTime($context, $timeLit, $tzLit, $immutable);
            if (null === $parsed) {
                return self::emitParseFailure($context, $function, $timeLit);
            }

            return self::materializeDateTimeLike(
                $context,
                $immutable,
                $parsed['timestamp'],
                $parsed['microsecond'],
                $parsed['timezone']
            );
        }

        throw new \LogicException(
            $function.'() requires compile-time operands in this compiler build (issue #4124)'
        );
    }

    private static function compileTimeStringArg(JITVariable $arg): ?string
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return $lit;
        }

        return $arg->compileTimeString;
    }

    /**
     * @return array{timestamp: int, microsecond: int, timezone: string}|null
     */
    private static function parseAtCompileTime(
        Context $context,
        string $time,
        ?string $tzName,
        bool $immutable
    ): ?array {
        $vmCtx = $context->runtime->vmContext;
        if (null === $vmCtx) {
            throw new \LogicException('date_create() requires VM context at JIT compile time (#4124)');
        }
        $timezone = null;
        if (null !== $tzName && '' !== $tzName) {
            try {
                $zoneVar = DateTimeSupport::newDateTimeZoneVariable($vmCtx, $tzName);
                $timezone = $zoneVar->toObject();
            } catch (NativeDateInvalidTimeZoneException) {
                return null;
            }
        }
        $created = $immutable
            ? DateTimeSupport::tryNewDateTimeImmutableVariable($vmCtx, $time, $timezone)
            : DateTimeSupport::tryNewDateTimeVariable($vmCtx, $time, $timezone);
        if (null === $created) {
            return null;
        }
        $obj = $created->toObject();

        return [
            'timestamp' => $obj->getProperty(DateTimeSupport::TS_PROPERTY)->resolveIndirect()->toInt(),
            'microsecond' => $obj->getProperty(DateTimeSupport::MICROSECOND_PROPERTY)->resolveIndirect()->toInt(),
            'timezone' => $obj->getProperty(DateTimeSupport::TZ_PROPERTY)->resolveIndirect()->toString(),
        ];
    }

    private static function compileTimeTimezoneName(Context $context, JITVariable $arg, string $function): ?string
    {
        $lit = self::compileTimeStringArg($arg);
        if (null !== $lit) {
            return $lit;
        }
        if (JITVariable::TYPE_OBJECT !== $arg->type) {
            return null;
        }
        $prop = $context->type->object->propertyFetch(
            $arg->value,
            'DateTimeZone',
            DateTimeSupport::TZ_NAME_PROPERTY
        );

        return self::compileTimeStringArg($prop);
    }

    private static function emitParseFailure(Context $context, string $function, string $timeLit): Value
    {
        // php-src ext/date/php_date.c — false on failure without E_WARNING (#16488, #10010).
        unset($function, $timeLit);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));

        return $ptr;
    }

    private static function materializeDateTimeLike(
        Context $context,
        bool $immutable,
        int $timestamp,
        int $microsecond,
        string $tzName
    ): Value {
        $className = $immutable ? 'DateTimeImmutable' : 'DateTime';
        $objectType = $context->type->object;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $i64 = $context->getTypeFromString('int64');

        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TS_PROPERTY),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($timestamp, false)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::MICROSECOND_PROPERTY),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($microsecond, false)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $tzVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString($tzName))
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TZ_PROPERTY),
            $tzVar,
            JITVariable::TYPE_STRING
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeObject'), $ptr, $obj);

        return $ptr;
    }
}
