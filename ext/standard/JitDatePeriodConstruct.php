<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DatePeriodSupport;
use PHPLLVM\Value;

/**
 * DatePeriod::__construct(DateTimeInterface, DateInterval, DateTimeInterface [, int $options]) — JIT/AOT (#26772).
 *
 * php-src: ext/date/php_date.c — date_period_construct end-date form
 * ISO-8601 / recurrence overloads remain VM / createFromISO8601String.
 */
final class JitDatePeriodConstruct
{
    private const CLASS_PERIOD = 'DatePeriod';

    public static function invokeFromEndDate(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 4) {
            throw new \ArgumentCountError(
                'DatePeriod::__construct() end-date form expects at least 3 arguments in this compiler build (#26772)'
            );
        }
        $options = 0;
        if (isset($args[4])) {
            if (JITVariable::TYPE_NATIVE_LONG === $args[4]->type
                && null !== ($args[4]->compileTimeLong ?? null)
            ) {
                $options = (int) $args[4]->compileTimeLong;
            } elseif (JITVariable::TYPE_NATIVE_LONG === $args[4]->type) {
                try {
                    $options = (int) $args[4]->value->getConstantValue();
                } catch (\Throwable) {
                    throw new \LogicException(
                        'DatePeriod::__construct() options must be a compile-time int in this build (#26772)'
                    );
                }
            } else {
                throw new \LogicException(
                    'DatePeriod::__construct() options must be a compile-time int in this build (#26772)'
                );
            }
        }

        $includeStart = 0 === ($options & DatePeriodSupport::OPTION_EXCLUDE_START_DATE);
        $includeEnd = 0 !== ($options & DatePeriodSupport::OPTION_INCLUDE_END_DATE);
        $recurrences = ($includeStart ? 1 : 0) + ($includeEnd ? 1 : 0);

        $period = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $start = ReflectionSetup::loadObjectFromArg($context, $args[1]);
        $interval = ReflectionSetup::loadObjectFromArg($context, $args[2]);
        $end = ReflectionSetup::loadObjectFromArg($context, $args[3]);

        self::storeObjectProperty($context, $period, 'start', $start);
        self::storeNullProperty($context, $period, 'current');
        self::storeObjectProperty($context, $period, 'end', $end);
        self::storeObjectProperty($context, $period, 'interval', $interval);
        self::storeLongProperty($context, $period, 'recurrences', $recurrences);
        self::storeBoolProperty($context, $period, 'include_start_date', $includeStart);
        self::storeBoolProperty($context, $period, 'include_end_date', $includeEnd);
        self::storeLongProperty($context, $period, '__dp_iter_key', 0);
        self::storeBoolProperty($context, $period, '__dp_iter_started', false);
        ReflectionSetup::markConstructed($context, $period);
        self::stampCompileTimeForeachSnapshot($args[0], $args[1], $args[2], $args[3], $includeStart, $includeEnd);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    private static function storeObjectProperty(
        Context $context,
        Value $period,
        string $prop,
        Value $propObj
    ): void {
        // Object slots hold __object__* — not a boxed __value__* (#26772).
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $propObj
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($period, self::CLASS_PERIOD, $prop),
            $propVar,
            JITVariable::TYPE_OBJECT
        );
    }

    private static function storeNullProperty(Context $context, Value $period, string $prop): void
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($period, self::CLASS_PERIOD, $prop),
            $propVar,
            JITVariable::TYPE_NULL
        );
    }

    private static function storeLongProperty(Context $context, Value $period, string $prop, int $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($period, self::CLASS_PERIOD, $prop),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($value, false)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
    }

    private static function storeBoolProperty(Context $context, Value $period, string $prop, bool $value): void
    {
        $i1 = $context->getTypeFromString('int1');
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($period, self::CLASS_PERIOD, $prop),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_BOOL,
                JITVariable::KIND_VALUE,
                $i1->constInt($value ? 1 : 0, false)
            ),
            JITVariable::TYPE_NATIVE_BOOL
        );
    }

    /**
     * Stamp ordered timestamps for thin-AOT foreach (hashtable path, #26772).
     */
    private static function stampCompileTimeForeachSnapshot(
        JITVariable $periodVar,
        JITVariable $startVar,
        JITVariable $intervalVar,
        JITVariable $endVar,
        bool $includeStart,
        bool $includeEnd
    ): void {
        $startTs = $startVar->compileTimeLong;
        $endTs = $endVar->compileTimeLong;
        $interval = $intervalVar->compileTimeDateInterval;
        if (null === $startTs || null === $endTs || null === $interval) {
            return;
        }
        $delta = ((int) $interval['d']) * 86400
            + ((int) $interval['h']) * 3600
            + ((int) $interval['i']) * 60
            + ((int) $interval['s']);
        if ($delta <= 0) {
            return;
        }
        if (0 !== (int) ($interval['invert'] ?? 0)) {
            $delta = -$delta;
        }
        $timestamps = [];
        $t = $startTs;
        if (!$includeStart) {
            $t += $delta;
        }
        $guard = 0;
        while ($guard < 100000) {
            ++$guard;
            if ($includeEnd) {
                if ($t > $endTs) {
                    break;
                }
            } elseif ($t >= $endTs) {
                break;
            }
            $timestamps[] = $t;
            $t += $delta;
        }
        $periodVar->compileTimeDatePeriodTimestamps = $timestamps;
        $periodVar->compileTimeDatePeriodTimezone = $startVar->compileTimeString ?? 'UTC';
    }
}
