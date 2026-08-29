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
 * DatePeriod::__construct — end-date + int-$recurrences forms for JIT/AOT (#26772, #26852).
 *
 * php-src: ext/date/php_date.c — date_period_construct
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
        $options = self::compileTimeOptions($args[4] ?? null);
        $includeStart = 0 === ($options & DatePeriodSupport::OPTION_EXCLUDE_START_DATE);
        $includeEnd = 0 !== ($options & DatePeriodSupport::OPTION_INCLUDE_END_DATE);
        $recurrences = ($includeStart ? 1 : 0) + ($includeEnd ? 1 : 0);

        $period = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $interval = ReflectionSetup::loadObjectFromArg($context, $args[2]);

        self::storeObjectProperty(
            $context,
            $period,
            'start',
            JitDateTimeConstruct::materializeOwnedFromArg($context, $args[1])
        );
        self::storeNullProperty($context, $period, 'current');
        self::storeObjectProperty(
            $context,
            $period,
            'end',
            JitDateTimeConstruct::materializeOwnedFromArg($context, $args[3])
        );
        self::storeObjectProperty($context, $period, 'interval', self::cloneForStorage($context, $interval));
        self::storeLongProperty($context, $period, 'recurrences', $recurrences);
        self::storeBoolProperty($context, $period, 'include_start_date', $includeStart);
        self::storeBoolProperty($context, $period, 'include_end_date', $includeEnd);
        self::storeLongProperty($context, $period, '__dp_iter_key', 0);
        self::storeBoolProperty($context, $period, '__dp_iter_started', false);
        ReflectionSetup::markConstructed($context, $period);
        self::stampCompileTimeForeachSnapshotEndDate(
            $args[0],
            $args[1],
            $args[2],
            $args[3],
            $includeStart,
            $includeEnd
        );
        self::stampCompileTimeSerializeBag(
            $args[0],
            $args[1],
            $args[2],
            $args[3],
            $recurrences,
            $includeStart,
            $includeEnd
        );

        return self::returnNullSlot($context);
    }

    /**
     * DatePeriod(DateTimeInterface, DateInterval, int $recurrences [, int $options]) (#26852).
     *
     * php-src stores userRecurrences+1 in the recurrences property when include_start
     * (includes start slot); EXCLUDE_START_DATE keeps property == ctor arg (#34626 / #26852).
     */
    public static function invokeFromRecurrenceCount(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 4) {
            throw new \ArgumentCountError(
                'DatePeriod::__construct() recurrence form expects at least 3 arguments (#26852)'
            );
        }
        $userRecurrences = self::compileTimeIntArg(
            $args[3],
            'DatePeriod::__construct() $recurrences must be a compile-time int (#26852)'
        );
        if ($userRecurrences < 1) {
            throw new \Exception('DatePeriod::__construct(): Recurrence count must be greater than 0');
        }
        $options = self::compileTimeOptions($args[4] ?? null);
        $includeStart = 0 === ($options & DatePeriodSupport::OPTION_EXCLUDE_START_DATE);
        // php-src: include_start → property = userRecurrences+1; EXCLUDE_START → property =
        // userRecurrences (wire/public prop already equals foreach count — #34626 / #26852).
        $storedRecurrences = $includeStart ? ($userRecurrences + 1) : $userRecurrences;

        $period = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $interval = ReflectionSetup::loadObjectFromArg($context, $args[2]);

        self::storeObjectProperty(
            $context,
            $period,
            'start',
            JitDateTimeConstruct::materializeOwnedFromArg($context, $args[1])
        );
        self::storeNullProperty($context, $period, 'current');
        self::storeNullProperty($context, $period, 'end');
        self::storeObjectProperty($context, $period, 'interval', self::cloneForStorage($context, $interval));
        self::storeLongProperty($context, $period, 'recurrences', $storedRecurrences);
        self::storeBoolProperty($context, $period, 'include_start_date', $includeStart);
        self::storeBoolProperty($context, $period, 'include_end_date', false);
        self::storeLongProperty($context, $period, '__dp_iter_key', 0);
        self::storeBoolProperty($context, $period, '__dp_iter_started', false);
        ReflectionSetup::markConstructed($context, $period);
        self::stampCompileTimeForeachSnapshotRecurrences(
            $args[0],
            $args[1],
            $args[2],
            $userRecurrences,
            $includeStart
        );
        self::stampCompileTimeSerializeBag(
            $args[0],
            $args[1],
            $args[2],
            null,
            $storedRecurrences,
            $includeStart,
            false
        );

        return self::returnNullSlot($context);
    }

    private static function returnNullSlot(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    private static function compileTimeOptions(?JITVariable $arg): int
    {
        if (null === $arg) {
            return 0;
        }

        return self::compileTimeIntArg(
            $arg,
            'DatePeriod::__construct() options must be a compile-time int in this build (#26772)'
        );
    }

    private static function compileTimeIntArg(JITVariable $arg, string $error): int
    {
        if (null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            try {
                return (int) $arg->value->getConstantValue();
            } catch (\Throwable) {
                throw new \LogicException($error);
            }
        }

        throw new \LogicException($error);
    }

    private static function cloneForStorage(Context $context, Value $propObj): Value
    {
        // php-src DatePeriodSupport::cloneDateTimeForStorage — ctor temps lose backing slots (#15124).
        return $context->type->object->cloneObject($propObj);
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
        // Object-typed DatePeriod slots (`end` / `current`) must store a null `__object__*`,
        // not a null `__value__*` box — otherwise getEndDate cannot distinguish end-date vs
        // recurrence forms (#27572).
        $nullObj = $context->getTypeFromString('__object__*')->constNull();
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $nullObj
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($period, self::CLASS_PERIOD, $prop),
            $propVar,
            JITVariable::TYPE_OBJECT
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
    private static function stampCompileTimeForeachSnapshotEndDate(
        JITVariable $periodVar,
        JITVariable $startVar,
        JITVariable $intervalVar,
        JITVariable $endVar,
        bool $includeStart,
        bool $includeEnd
    ): void {
        $startTs = self::compileTimeUnixTs($startVar);
        $endTs = self::compileTimeUnixTs($endVar);
        $delta = self::intervalDeltaSeconds($intervalVar);
        if (null === $startTs || null === $endTs || null === $delta || 0 === $delta) {
            return;
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
        $periodVar->compileTimeDatePeriodTimezone = self::compileTimeTz($startVar);
    }

    /**
     * Recurrence-count foreach snapshot: userRecurrences intervals after start (#26852).
     * With include_start: userRecurrences+1 dates; excluded start: userRecurrences dates.
     */
    private static function stampCompileTimeForeachSnapshotRecurrences(
        JITVariable $periodVar,
        JITVariable $startVar,
        JITVariable $intervalVar,
        int $userRecurrences,
        bool $includeStart
    ): void {
        $startTs = self::compileTimeUnixTs($startVar);
        $delta = self::intervalDeltaSeconds($intervalVar);
        if (null === $startTs || null === $delta || 0 === $delta) {
            return;
        }
        $timestamps = [];
        $t = $startTs;
        if (!$includeStart) {
            $t += $delta;
        }
        $limit = $includeStart ? ($userRecurrences + 1) : $userRecurrences;
        for ($i = 0; $i < $limit; ++$i) {
            $timestamps[] = $t;
            $t += $delta;
        }
        $periodVar->compileTimeDatePeriodTimestamps = $timestamps;
        $periodVar->compileTimeDatePeriodTimezone = self::compileTimeTz($startVar);
    }

    /**
     * DateTime instant after #32691 lives on {@see JITVariable::$compileTimeDateTimeTimestamp}
     * so assignToPointer does not writeLong the object. Fall back to compileTimeLong for
     * older stamp sites.
     */
    private static function compileTimeUnixTs(JITVariable $arg): ?int
    {
        if (null !== $arg->compileTimeDateTimeTimestamp) {
            return (int) $arg->compileTimeDateTimeTimestamp;
        }
        if (null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }

        return null;
    }

    private static function compileTimeTz(JITVariable $arg): string
    {
        $tz = $arg->compileTimeTimezoneName;
        if (null !== $tz && '' !== $tz) {
            return $tz;
        }
        $legacy = $arg->compileTimeString;
        if (null !== $legacy && '' !== $legacy && !str_starts_with(strtolower($legacy), 'datetime')) {
            return $legacy;
        }

        return 'UTC';
    }

    private static function intervalDeltaSeconds(JITVariable $intervalVar): ?int
    {
        $interval = $intervalVar->compileTimeDateInterval;
        if (null === $interval) {
            return null;
        }
        $delta = ((int) $interval['d']) * 86400
            + ((int) $interval['h']) * 3600
            + ((int) $interval['i']) * 60
            + ((int) $interval['s']);
        if (0 !== (int) ($interval['invert'] ?? 0)) {
            $delta = -$delta;
        }

        return $delta;
    }

    /**
     * Stamp serialize bag for thin-AOT fold (#34585 / peer #34576 DateTime wire).
     */
    private static function stampCompileTimeSerializeBag(
        JITVariable $periodVar,
        JITVariable $startVar,
        JITVariable $intervalVar,
        ?JITVariable $endVar,
        int $recurrences,
        bool $includeStart,
        bool $includeEnd
    ): void {
        $startStamp = self::dateTimeSerializeStamp($startVar);
        $interval = $intervalVar->compileTimeDateInterval;
        if (null === $startStamp || !\is_array($interval)) {
            return;
        }
        $endStamp = null;
        if (null !== $endVar) {
            $endStamp = self::dateTimeSerializeStamp($endVar);
            if (null === $endStamp) {
                return;
            }
        }
        $periodVar->compileTimeDatePeriodSerialize = [
            'start' => $startStamp,
            'end' => $endStamp,
            'interval' => [
                'y' => (int) $interval['y'],
                'm' => (int) $interval['m'],
                'd' => (int) $interval['d'],
                'h' => (int) $interval['h'],
                'i' => (int) $interval['i'],
                's' => (int) $interval['s'],
                'f' => (float) $interval['f'],
                'invert' => (int) $interval['invert'],
            ],
            'recurrences' => $recurrences,
            'include_start_date' => $includeStart,
            'include_end_date' => $includeEnd,
        ];
        // Drop any DateTime instant leaked onto $this via mis-indexed local restore (#34591).
        $periodVar->compileTimeDateTimeTimestamp = null;
        $periodVar->compileTimeDateTimeMicrosecond = null;
        $periodVar->compileTimeDateTimeClassName = null;
        if ('DateTime' === ($periodVar->classUserType ?? '')
            || 'DateTimeImmutable' === ($periodVar->classUserType ?? '')) {
            $periodVar->classUserType = self::CLASS_PERIOD;
        }
    }

    /**
     * @return array{timestamp: int, micro: int, tz: string, class: string}|null
     */
    private static function dateTimeSerializeStamp(JITVariable $arg): ?array
    {
        if (null === $arg->compileTimeDateTimeTimestamp) {
            return null;
        }
        $class = $arg->compileTimeDateTimeClassName;
        if (null === $class || '' === $class) {
            $class = $arg->classUserType;
        }
        if (null === $class || '' === $class) {
            $class = 'DateTime';
        }
        if ('DateTime' !== $class && 'DateTimeImmutable' !== $class) {
            $class = 'DateTime';
        }

        return [
            'timestamp' => (int) $arg->compileTimeDateTimeTimestamp,
            'micro' => (int) ($arg->compileTimeDateTimeMicrosecond ?? 0),
            'tz' => self::compileTimeTz($arg),
            'class' => $class,
        ];
    }
}
