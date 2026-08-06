<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DatePeriodSupport;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\NativeDateMalformedPeriodStringException;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DatePeriod::createFromISO8601String() (#7296, #16796, ext/date/php_date.c).
 *
 * php-src: ext/date/php_date.c — PHP_METHOD(DatePeriod, createFromISO8601String)
 */
final class JitDatePeriodCreateFromISO8601String
{
    private const CLASS_PERIOD = 'DatePeriod';

    private const CLASS_INTERVAL = 'DateInterval';

    private const CLASS_DATETIME = 'DateTimeImmutable';

    /** @var list<int>|null Thin-AOT foreach snapshot from last successful invoke (#26937). */
    private static ?array $lastCompileTimeTimestamps = null;

    private static ?string $lastCompileTimeTimezone = null;

    /**
     * @return array{timestamps: list<int>, timezone: string}|null
     */
    public static function takeLastCompileTimeForeachSnapshot(): ?array
    {
        $timestamps = self::$lastCompileTimeTimestamps;
        $timezone = self::$lastCompileTimeTimezone;
        self::$lastCompileTimeTimestamps = null;
        self::$lastCompileTimeTimezone = null;
        if (null === $timestamps) {
            return null;
        }

        return [
            'timestamps' => $timestamps,
            'timezone' => $timezone ?? 'UTC',
        ];
    }

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        self::$lastCompileTimeTimestamps = null;
        self::$lastCompileTimeTimezone = null;

        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'DatePeriod::createFromISO8601String() expects 1 or 2 arguments, %d given',
                $argc
            ));
        }

        $lit = self::compileTimeStringArg($args[0]);
        if (null === $lit) {
            throw new \LogicException(
                'DatePeriod::createFromISO8601String() requires compile-time string operands in this compiler build (#16796)'
            );
        }

        $options = 0;
        if (2 === $argc) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type
                && JITVariable::TYPE_VALUE !== $args[1]->type) {
                throw new \LogicException(
                    'DatePeriod::createFromISO8601String() options must be a compile-time int literal (#16796)'
                );
            }
            if (JITVariable::TYPE_NATIVE_LONG === $args[1]->type) {
                $options = (int) $args[1]->value->getConstantValue();
            } else {
                throw new \LogicException(
                    'DatePeriod::createFromISO8601String() options must be a compile-time int literal (#16796)'
                );
            }
        }

        $vmCtx = $context->runtime->vmContext;
        if (null === $vmCtx) {
            throw new \LogicException('DatePeriod::createFromISO8601String() requires VM context at JIT compile time (#16796)');
        }

        try {
            $period = DatePeriodSupport::createFromISO8601String($vmCtx, $lit, $options);
        } catch (NativeDateMalformedPeriodStringException $e) {
            TryCatchHelper::emitCatchableClassError(
                $context,
                'DateMalformedPeriodStringException',
                $e->getMessage()
            );
            $slot = JitValueBox::alloc($context);

            return $slot;
        }

        self::stampCompileTimeForeachSnapshot($period);

        return self::materializeDatePeriod($context, $period);
    }

    /**
     * Walk the VM period with the Iterator SSOT and stamp timestamps for thin-AOT foreach (#26937).
     */
    private static function stampCompileTimeForeachSnapshot(ObjectEntry $period): void
    {
        $timestamps = [];
        $timezone = 'UTC';
        DatePeriodSupport::iteratorRewind($period);
        $guard = 0;
        while (DatePeriodSupport::iteratorValid($period) && $guard < 100000) {
            ++$guard;
            $current = DatePeriodSupport::iteratorCurrent($period);
            if (null === $current) {
                break;
            }
            $timestamps[] = $current->getProperty(DateTimeSupport::TS_PROPERTY)->resolveIndirect()->toInt();
            $timezone = $current->getProperty(DateTimeSupport::TZ_PROPERTY)->resolveIndirect()->toString();
            DatePeriodSupport::iteratorNext($period);
        }
        self::$lastCompileTimeTimestamps = $timestamps;
        self::$lastCompileTimeTimezone = $timezone;
    }

    private static function compileTimeStringArg(JITVariable $arg): ?string
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return $lit;
        }

        return $arg->compileTimeString;
    }

    private static function materializeDatePeriod(Context $context, ObjectEntry $period): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_PERIOD);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        $start = $period->getProperty('start')->resolveIndirect()->toObject();
        $interval = $period->getProperty('interval')->resolveIndirect()->toObject();
        $endProp = $period->getProperty('end')->resolveIndirect();
        $end = VmVariable::TYPE_NULL !== $endProp->type ? $endProp->toObject() : null;

        self::storeObjectProperty($context, $obj, self::CLASS_PERIOD, 'start', self::materializeDateTime($context, $start));
        self::storeObjectProperty($context, $obj, self::CLASS_PERIOD, 'interval', self::materializeDateInterval($context, $interval));
        if (null !== $end) {
            self::storeObjectProperty($context, $obj, self::CLASS_PERIOD, 'end', self::materializeDateTime($context, $end));
        } else {
            self::storeNullProperty($context, $obj, self::CLASS_PERIOD, 'current');
            self::storeNullProperty($context, $obj, self::CLASS_PERIOD, 'end');
        }

        $recurrences = $period->getProperty('recurrences')->resolveIndirect()->toInt();
        $includeStart = $period->getProperty('include_start_date')->resolveIndirect()->toBool();
        $includeEnd = $period->getProperty('include_end_date')->resolveIndirect()->toBool();
        self::storeLongProperty($context, $obj, self::CLASS_PERIOD, 'recurrences', $recurrences);
        self::storeBoolProperty($context, $obj, self::CLASS_PERIOD, 'include_start_date', $includeStart);
        self::storeBoolProperty($context, $obj, self::CLASS_PERIOD, 'include_end_date', $includeEnd);
        self::storeLongProperty($context, $obj, self::CLASS_PERIOD, '__dp_iter_key', 0);
        self::storeBoolProperty($context, $obj, self::CLASS_PERIOD, '__dp_iter_started', false);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeObject'), $ptr, $obj);

        return $ptr;
    }

    private static function materializeDateTime(Context $context, ObjectEntry $dateTime): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_DATETIME);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $i64 = $context->getTypeFromString('int64');

        $ts = $dateTime->getProperty(DateTimeSupport::TS_PROPERTY)->resolveIndirect()->toInt();
        $micro = $dateTime->getProperty(DateTimeSupport::MICROSECOND_PROPERTY)->resolveIndirect()->toInt();
        $tz = $dateTime->getProperty(DateTimeSupport::TZ_PROPERTY)->resolveIndirect()->toString();

        self::storeLongProperty($context, $obj, self::CLASS_DATETIME, DateTimeSupport::TS_PROPERTY, $ts);
        self::storeLongProperty($context, $obj, self::CLASS_DATETIME, DateTimeSupport::MICROSECOND_PROPERTY, $micro);
        $tzVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString($tz))
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_DATETIME, DateTimeSupport::TZ_PROPERTY),
            $tzVar,
            JITVariable::TYPE_STRING
        );

        return $obj;
    }

    private static function materializeDateInterval(Context $context, ObjectEntry $interval): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_INTERVAL);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');

        foreach (['y', 'm', 'd', 'h', 'i', 's', 'invert'] as $name) {
            self::storeLongProperty(
                $context,
                $obj,
                self::CLASS_INTERVAL,
                $name,
                $interval->getProperty($name)->resolveIndirect()->toInt()
            );
        }
        $fVal = $interval->getProperty('f')->resolveIndirect()->toFloat();
        $fSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            JitValueBox::pointer($context, $fSlot),
            $context->constantFromFloat($fVal)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_INTERVAL, 'f'),
            new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $fSlot),
            JITVariable::TYPE_VALUE
        );
        self::storeBoolProperty($context, $obj, self::CLASS_INTERVAL, 'days', false);

        return $obj;
    }

    private static function storeObjectProperty(
        Context $context,
        Value $obj,
        string $className,
        string $prop,
        Value $propObj
    ): void {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $propObj
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, $className, $prop),
            $propVar,
            JITVariable::TYPE_OBJECT
        );
    }

    private static function storeNullProperty(Context $context, Value $obj, string $className, string $prop): void
    {
        // Null `__object__*` for object-typed DatePeriod slots (#27572).
        $nullObj = $context->getTypeFromString('__object__*')->constNull();
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $nullObj
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, $className, $prop),
            $propVar,
            JITVariable::TYPE_OBJECT
        );
    }

    private static function storeLongProperty(
        Context $context,
        Value $obj,
        string $className,
        string $prop,
        int $value
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, $className, $prop),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($value, false)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
    }

    private static function storeBoolProperty(
        Context $context,
        Value $obj,
        string $className,
        string $prop,
        bool $value
    ): void {
        $i1 = $context->getTypeFromString('int1');
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, $className, $prop),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_BOOL,
                JITVariable::KIND_VALUE,
                $i1->constInt($value ? 1 : 0, false)
            ),
            JITVariable::TYPE_NATIVE_BOOL
        );
    }
}
