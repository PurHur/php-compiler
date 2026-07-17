<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmDateInterval;
use PHPCompiler\Frame;

/**
 * Shared helpers for DatePeriod VM builtins (issue #14144, php-src ext/date/php_date.c).
 */
final class DatePeriodSupport
{
    public const CLASS_DATEPERIOD = 'dateperiod';

    /** php-src date_period_construct — accepted overload list (#15431). */
    public const CONSTRUCTOR_SIGNATURE_TYPE_ERROR =
        'DatePeriod::__construct() accepts (DateTimeInterface, DateInterval, int [, int]), '
        .'or (DateTimeInterface, DateInterval, DateTime [, int]), or (string [, int]) as arguments';

    /** php-src PHP_DATE_PERIOD_INCLUDE_END_DATE — end-date ctor overload sentinel. */
    public const RECURRENCES_END_DATE = 2147483648;

    public const OPTION_EXCLUDE_START_DATE = 1;

    public const OPTION_INCLUDE_END_DATE = 2;

    /**
     * php-src REGISTER_DATEPERIOD_CLASS_CONST_LONG — discovery via defined()/Reflection (#20071).
     *
     * @var array<string, int> lowercase constant name => value
     */
    private const CLASS_CONSTANTS = [
        'exclude_start_date' => self::OPTION_EXCLUDE_START_DATE,
        'include_end_date' => self::OPTION_INCLUDE_END_DATE,
    ];

    /**
     * Register DatePeriod::EXCLUDE_START_DATE / INCLUDE_END_DATE on the class entry (#20071).
     *
     * php-src: ext/date/php_date.c — REGISTER_DATEPERIOD_CLASS_CONST_LONG
     */
    public static function registerClassConstants(ClassEntry $entry): void
    {
        foreach (self::CLASS_CONSTANTS as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = strtoupper($name);
        }
    }

    /**
     * php-src date_period_construct — reject unknown overload shapes before mutating state (#15431).
     */
    public static function assertConstructorOverload(Frame $frame, int $argc, Context $ctx): void
    {
        $userArgs = $argc - 1;
        if ($userArgs < 1 || $userArgs > 4) {
            return;
        }
        $arg1 = $frame->calledArgs[1]->resolveIndirect();
        if ($userArgs <= 2) {
            if (Variable::TYPE_STRING !== $arg1->type) {
                throw new \TypeError(self::CONSTRUCTOR_SIGNATURE_TYPE_ERROR);
            }

            return;
        }
        if (Variable::TYPE_OBJECT !== $arg1->type) {
            throw new \TypeError(self::CONSTRUCTOR_SIGNATURE_TYPE_ERROR);
        }
        $start = $arg1->toObject();
        if (!InterfaceCheck::entryIsInstanceOf($start->class, DateTimeSupport::CLASS_DATETIMEINTERFACE, $ctx)) {
            throw new \TypeError(self::CONSTRUCTOR_SIGNATURE_TYPE_ERROR);
        }
        $arg2 = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $arg2->type) {
            throw new \TypeError(self::CONSTRUCTOR_SIGNATURE_TYPE_ERROR);
        }
        if (DateIntervalSupport::CLASS_DATEINTERVAL !== strtolower($arg2->toObject()->class->name)) {
            throw new \TypeError(self::CONSTRUCTOR_SIGNATURE_TYPE_ERROR);
        }
        $arg3 = $frame->calledArgs[3]->resolveIndirect();
        if (Variable::TYPE_INTEGER === $arg3->type) {
            return;
        }
        if (Variable::TYPE_OBJECT === $arg3->type) {
            $end = $arg3->toObject();
            if (InterfaceCheck::entryIsInstanceOf($end->class, DateTimeSupport::CLASS_DATETIMEINTERFACE, $ctx)) {
                return;
            }
        }
        throw new \TypeError(self::CONSTRUCTOR_SIGNATURE_TYPE_ERROR);
    }

    public static function requireDatePeriod(
        Variable $var,
        string $label,
        ?int $argNum = null,
        ?string $argName = null
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw self::typeError($label, $argNum, $argName, $var);
        }
        $obj = $var->toObject();
        if (self::CLASS_DATEPERIOD !== strtolower($obj->class->name)) {
            throw self::typeError($label, $argNum, $argName, $var, $obj->class->name);
        }

        return $obj;
    }

    /** php-src date_period_initialize — start/interval/recurrences form (#14144). */
    public static function initFromRecurrenceCount(
        ObjectEntry $period,
        ObjectEntry $start,
        ObjectEntry $interval,
        int $recurrences,
        int $options = 0,
        ?Context $ctx = null
    ): void {
        if ($recurrences < 1) {
            throw new \Exception('DatePeriod::__construct(): Recurrence count must be greater than 0');
        }
        self::setObjectProperty($period, 'start', self::cloneDateTimeForStorage($start, $ctx));
        self::setNullProperty($period, 'current');
        self::setNullProperty($period, 'end');
        self::setObjectProperty($period, 'interval', self::cloneIntervalForStorage($interval, $ctx));
        self::requireIntProperty($period, 'recurrences')->int($recurrences + 1);
        self::requireBoolProperty($period, 'include_start_date')->bool(0 === ($options & self::OPTION_EXCLUDE_START_DATE));
        self::requireBoolProperty($period, 'include_end_date')->bool(false);
        $period->constructed = true;
        $period->datePeriodIterator = null;
    }

    /** php-src date_period_initialize — start/interval/end form (#14228). */
    public static function initFromEndDate(
        ObjectEntry $period,
        ObjectEntry $start,
        ObjectEntry $interval,
        ObjectEntry $end,
        int $options = 0,
        ?Context $ctx = null
    ): void {
        self::setObjectProperty($period, 'start', self::cloneDateTimeForStorage($start, $ctx));
        self::setNullProperty($period, 'current');
        self::setObjectProperty($period, 'end', self::cloneDateTimeForStorage($end, $ctx));
        self::setObjectProperty($period, 'interval', self::cloneIntervalForStorage($interval, $ctx));
        self::requireIntProperty($period, 'recurrences')->int(self::RECURRENCES_END_DATE);
        self::requireBoolProperty($period, 'include_start_date')->bool(0 === ($options & self::OPTION_EXCLUDE_START_DATE));
        self::requireBoolProperty($period, 'include_end_date')->bool(0 !== ($options & self::OPTION_INCLUDE_END_DATE));
        $period->constructed = true;
        $period->datePeriodIterator = null;
    }

    public static function iteratorRewind(ObjectEntry $period): void
    {
        self::requireDatePeriodFromObject($period);
        $state = $period->datePeriodIterator ??= new DatePeriodIteratorState();
        $state->key = 0;
        $state->started = true;

        $start = self::requireObjectProperty($period, 'start');
        $interval = self::requireObjectProperty($period, 'interval');
        $includeStart = self::requireBoolProperty($period, 'include_start_date')->toBool();

        $current = DateTimeSupport::cloneDateTimeLike($start);
        if (!$includeStart) {
            DateTimeSupport::addInterval($current, $interval);
        }
        self::setObjectProperty($period, 'current', $current);
    }

    public static function iteratorValid(ObjectEntry $period): bool
    {
        self::requireDatePeriodFromObject($period);
        $state = $period->datePeriodIterator;
        if (null === $state || !$state->started) {
            return false;
        }
        $current = self::currentObjectProperty($period);
        if (null === $current) {
            return false;
        }

        $recurrences = self::requireIntProperty($period, 'recurrences')->toInt();
        if (self::RECURRENCES_END_DATE === $recurrences) {
            $end = self::objectProperty($period, 'end');
            if (null === $end) {
                return false;
            }
            $cmp = self::compareDateTimeObjects($current, $end);
            if (self::requireBoolProperty($period, 'include_end_date')->toBool()) {
                return $cmp <= 0;
            }

            return $cmp < 0;
        }

        return $state->key < $recurrences;
    }

    public static function iteratorCurrent(ObjectEntry $period): ?ObjectEntry
    {
        self::requireDatePeriodFromObject($period);
        $current = self::currentObjectProperty($period);
        if (null === $current) {
            return null;
        }

        return DateTimeSupport::cloneDateTimeLike($current);
    }

    public static function iteratorKey(ObjectEntry $period): int
    {
        self::requireDatePeriodFromObject($period);
        $state = $period->datePeriodIterator;

        return null !== $state ? $state->key : 0;
    }

    public static function iteratorNext(ObjectEntry $period): void
    {
        self::requireDatePeriodFromObject($period);
        $state = $period->datePeriodIterator;
        if (null === $state) {
            return;
        }
        ++$state->key;
        $current = self::currentObjectProperty($period);
        if (null === $current) {
            return;
        }
        $interval = self::requireObjectProperty($period, 'interval');
        DateTimeSupport::addInterval($current, $interval);
    }

    /** php-src date_period_get_start_date — clone of stored start (#16614). */
    public static function getStartDate(ObjectEntry $period): ObjectEntry
    {
        self::requireConstructedPeriod($period);
        $start = self::requireObjectProperty($period, 'start');

        return DateTimeSupport::cloneDateTimeLike($start);
    }

    /** php-src date_period_get_end_date — clone of stored end or null for recurrence-count form (#17495). */
    public static function getEndDate(ObjectEntry $period, ?Context $ctx = null): ?ObjectEntry
    {
        self::requireConstructedPeriod($period);
        $recurrences = self::requireIntProperty($period, 'recurrences')->toInt();
        if (self::RECURRENCES_END_DATE !== $recurrences) {
            return null;
        }
        $end = self::objectProperty($period, 'end');
        if (null === $end) {
            return null;
        }

        return self::cloneDateTimeForStorage($end, $ctx);
    }

    /** php-src date_period_get_date_interval — clone of stored interval (#16614). */
    public static function getDateInterval(ObjectEntry $period, ?Context $ctx = null): ObjectEntry
    {
        self::requireConstructedPeriod($period);
        $interval = self::requireObjectProperty($period, 'interval');

        return self::cloneIntervalForStorage($interval, $ctx);
    }

    /** php-src date_period_get_recurrences — user recurrence count or null for end-date form (#16614). */
    public static function getRecurrences(ObjectEntry $period): ?int
    {
        self::requireConstructedPeriod($period);
        $recurrences = self::requireIntProperty($period, 'recurrences')->toInt();
        if (self::RECURRENCES_END_DATE === $recurrences) {
            return null;
        }

        return $recurrences - 1;
    }

    /**
     * DatePeriod::createFromISO8601String() — php-src date_period_init_iso8601_string (#7296).
     */
    public static function createFromISO8601String(Context $ctx, string $spec, int $options = 0): ObjectEntry
    {
        $label = 'DatePeriod::createFromISO8601String()';
        $parsed = self::parseISO8601PeriodSpec($spec, $label);
        $class = $ctx->classes[self::CLASS_DATEPERIOD] ?? null;
        if (null === $class) {
            throw new \LogicException('DatePeriod is not registered in this compiler build');
        }

        $period = new ObjectEntry($class);
        $start = self::parsePeriodDateTimeImmutable($ctx, $parsed['start'], $label, $spec);
        $interval = self::parsePeriodInterval($ctx, $parsed['interval'], $label, $spec);

        if (null !== $parsed['end']) {
            $end = self::parsePeriodDateTimeImmutable($ctx, $parsed['end'], $label, $spec);
            self::initFromEndDate($period, $start, $interval, $end, $options, $ctx);
        } else {
            self::initFromRecurrenceCount($period, $start, $interval, $parsed['recurrences'], $options, $ctx);
        }

        return $period;
    }

    /**
     * @return array{start: string, end: ?string, interval: string, recurrences: int}
     *
     * @throws NativeDateMalformedPeriodStringException
     */
    public static function parseISO8601PeriodSpec(string $spec, string $label): array
    {
        $parts = explode('/', $spec);
        $recurrences = 0;
        $offset = 0;
        if (isset($parts[0]) && preg_match('/^R(\d*)$/', $parts[0], $matches)) {
            $recurrences = '' === $matches[1] ? 0 : (int) $matches[1];
            $offset = 1;
        }

        $body = \array_slice($parts, $offset);
        if (\count($body) < 2) {
            self::throwMalformedPeriodString($label, $spec, 'ISO interval must contain a start date');
        }

        // php-src timelib_strtointerval — interval may be start/end/duration or start/duration/end (#17280).
        $intervalIndex = null;
        foreach ($body as $i => $part) {
            if (\is_string($part) && str_starts_with($part, 'P')) {
                $intervalIndex = $i;
                break;
            }
        }
        if (null === $intervalIndex) {
            self::throwMalformedPeriodString($label, $spec, 'ISO interval must contain an interval');
        }
        $intervalSpec = $body[$intervalIndex];

        $dates = [];
        foreach ($body as $i => $part) {
            if ($i === $intervalIndex) {
                continue;
            }
            if (!\is_string($part) || '' === $part) {
                self::throwMalformedPeriodString($label, $spec, 'ISO interval must contain a start date');
            }
            $dates[] = $part;
        }
        if (0 === \count($dates) || \count($dates) > 2) {
            self::throwMalformedPeriodString($label, $spec, 'ISO interval must contain a start date');
        }

        $start = $dates[0];
        $end = $dates[1] ?? null;
        if (null === $end && 0 === $recurrences) {
            self::throwMalformedPeriodString(
                $label,
                $spec,
                'ISO interval must contain an end date or a recurrence count'
            );
        }

        return [
            'start' => $start,
            'end' => $end,
            'interval' => $intervalSpec,
            'recurrences' => $recurrences,
        ];
    }

    /**
     * @throws NativeDateMalformedPeriodStringException
     */
    private static function parsePeriodDateTimeImmutable(
        Context $ctx,
        string $time,
        string $label,
        string $spec
    ): ObjectEntry {
        $var = DateTimeSupport::tryNewDateTimeImmutableVariable($ctx, $time, null);
        if (null === $var) {
            self::throwMalformedPeriodString($label, $spec, 'ISO interval must contain a start date');
        }

        return $var->toObject();
    }

    /**
     * @throws NativeDateMalformedPeriodStringException
     */
    private static function parsePeriodInterval(
        Context $ctx,
        string $intervalSpec,
        string $label,
        string $spec
    ): ObjectEntry {
        try {
            VmDateInterval::parseSpec($intervalSpec);
        } catch (\Throwable) {
            self::throwMalformedPeriodString($label, $spec, 'ISO interval must contain an interval');
        }

        $class = $ctx->classes[DateIntervalSupport::CLASS_DATEINTERVAL] ?? null;
        if (null === $class) {
            throw new \LogicException('DateInterval is not registered in this compiler build');
        }
        $interval = new ObjectEntry($class);
        DateIntervalSupport::initDateInterval($interval, $intervalSpec);

        return $interval;
    }

    /**
     * @throws NativeDateMalformedPeriodStringException
     */
    private static function throwMalformedPeriodString(string $label, string $spec, string $reason): void
    {
        throw new NativeDateMalformedPeriodStringException(
            \sprintf('%s: %s, "%s" given', $label, $reason, $spec)
        );
    }

    private static function cloneIntervalForStorage(ObjectEntry $interval, ?Context $ctx): ObjectEntry
    {
        if (!$interval->constructed || null === $ctx) {
            return $interval;
        }

        return DateIntervalSupport::createFromState($ctx, DateIntervalSupport::readState($interval));
    }

    /**
     * Retain DateTime/DateTimeImmutable clones — inline ctor temps lose backing slots after dead-temp release (#15124).
     */
    private static function cloneDateTimeForStorage(ObjectEntry $dt, ?Context $ctx): ObjectEntry
    {
        if (!$dt->constructed || null === $ctx) {
            return $dt;
        }

        return DateTimeSupport::cloneDateTimeLike($dt);
    }

    private static function requireDatePeriodFromObject(ObjectEntry $period): void
    {
        if (self::CLASS_DATEPERIOD !== strtolower($period->class->name)) {
            throw new \LogicException('DatePeriod iterator called on non-DatePeriod object');
        }
    }

    private static function requireConstructedPeriod(ObjectEntry $period): void
    {
        self::requireDatePeriodFromObject($period);
        if (!$period->constructed) {
            throw new \LogicException('The DatePeriod object has not been initialized correctly');
        }
    }

    private static function requireObjectProperty(ObjectEntry $period, string $name): ObjectEntry
    {
        $obj = self::objectProperty($period, $name);
        if (null === $obj) {
            throw new \LogicException("DatePeriod property {$name} is missing in this compiler build");
        }

        return $obj;
    }

    private static function objectProperty(ObjectEntry $period, string $name): ?ObjectEntry
    {
        $var = self::requireProperty($period, $name)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return null;
        }

        return $var->toObject();
    }

    private static function currentObjectProperty(ObjectEntry $period): ?ObjectEntry
    {
        return self::objectProperty($period, 'current');
    }

    private static function compareDateTimeObjects(ObjectEntry $left, ObjectEntry $right): int
    {
        $leftTs = DateTimeSupport::readTimestamp($left);
        $rightTs = DateTimeSupport::readTimestamp($right);
        if ($leftTs < $rightTs) {
            return -1;
        }
        if ($leftTs > $rightTs) {
            return 1;
        }
        $leftUs = DateTimeSupport::readMicrosecond($left);
        $rightUs = DateTimeSupport::readMicrosecond($right);
        if ($leftUs < $rightUs) {
            return -1;
        }
        if ($leftUs > $rightUs) {
            return 1;
        }

        return 0;
    }

    /**
     * php-src ext/json/php_json.c — DatePeriod json encode wire (#14144).
     *
     * @return array<string, mixed>
     */
    public static function exportZendJsonWireDatePeriod(ObjectEntry $period): array
    {
        $startVar = self::requireProperty($period, 'start')->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $startVar->type) {
            throw new \LogicException('DatePeriod start property is missing in this compiler build');
        }
        $start = $startVar->toObject();
        $intervalVar = self::requireProperty($period, 'interval')->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $intervalVar->type) {
            throw new \LogicException('DatePeriod interval property is missing in this compiler build');
        }

        return [
            'start' => DateTimeSupport::exportZendJsonWireDateTimeLike($start),
            'current' => null,
            'end' => null,
            'interval' => DateIntervalSupport::exportZendJsonWireDateInterval($intervalVar->toObject()),
            'recurrences' => self::requireIntProperty($period, 'recurrences')->toInt(),
            'include_start_date' => self::requireBoolProperty($period, 'include_start_date')->toBool(),
            'include_end_date' => self::requireBoolProperty($period, 'include_end_date')->toBool(),
        ];
    }

    private static function setObjectProperty(ObjectEntry $period, string $name, ObjectEntry $value): void
    {
        $prop = self::requireProperty($period, $name);
        $prop->object($value);
    }

    private static function setNullProperty(ObjectEntry $period, string $name): void
    {
        $prop = self::requireProperty($period, $name);
        $prop->null();
    }

    private static function typeError(
        string $label,
        ?int $argNum,
        ?string $argName,
        Variable $var,
        ?string $objectClass = null
    ): \TypeError {
        $given = null !== $objectClass
            ? $objectClass
            : ReflectionSupport::valueTypeLabelPublic($var);
        if (null !== $argNum) {
            $param = null !== $argName ? " (\${$argName})" : '';

            return new \TypeError(
                "{$label}: Argument #{$argNum}{$param} must be of type DatePeriod, {$given} given"
            );
        }

        return new \TypeError("{$label} must be of type DatePeriod, {$given} given");
    }

    private static function requireProperty(ObjectEntry $obj, string $name): Variable
    {
        return $obj->getProperty($name);
    }

    private static function requireIntProperty(ObjectEntry $obj, string $name): Variable
    {
        $var = $obj->getProperty($name)->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \LogicException("DatePeriod property {$name} is missing in this compiler build");
        }

        return $var;
    }

    private static function requireBoolProperty(ObjectEntry $obj, string $name): Variable
    {
        $var = $obj->getProperty($name)->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \LogicException("DatePeriod property {$name} is missing in this compiler build");
        }

        return $var;
    }
}
