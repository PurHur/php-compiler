<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * IntlCalendar — Gregorian field get/set via zoneinfo (php-src calendar_*; #6151).
 *
 * v1 subset: createInstance, get/set (field + Y/M/D forms), getTimeZone, getTime/setTime.
 * ICU field constants match UCalendarDateFields (unicode/ucal.h).
 */
final class VmIntlCalendar
{
    public const CLASS_LC = 'intlcalendar';

    // UCalendarDateFields
    public const FIELD_ERA = 0;
    public const FIELD_YEAR = 1;
    public const FIELD_MONTH = 2;
    public const FIELD_WEEK_OF_YEAR = 3;
    public const FIELD_WEEK_OF_MONTH = 4;
    public const FIELD_DATE = 5;
    public const FIELD_DAY_OF_YEAR = 6;
    public const FIELD_DAY_OF_WEEK = 7;
    public const FIELD_DAY_OF_WEEK_IN_MONTH = 8;
    public const FIELD_AM_PM = 9;
    public const FIELD_HOUR = 10;
    public const FIELD_HOUR_OF_DAY = 11;
    public const FIELD_MINUTE = 12;
    public const FIELD_SECOND = 13;
    public const FIELD_MILLISECOND = 14;
    public const FIELD_ZONE_OFFSET = 15;
    public const FIELD_DST_OFFSET = 16;
    public const FIELD_YEAR_WOY = 17;
    public const FIELD_DOW_LOCAL = 18;
    public const FIELD_EXTENDED_YEAR = 19;
    public const FIELD_JULIAN_DAY = 20;
    public const FIELD_MILLISECONDS_IN_DAY = 21;
    public const FIELD_IS_LEAP_MONTH = 22;
    public const FIELD_FIELD_COUNT = 23;
    /** Synonym for FIELD_DATE (UCAL_DAY_OF_MONTH = UCAL_DATE). */
    public const FIELD_DAY_OF_MONTH = 5;

    public const DOW_SUNDAY = 1;
    public const DOW_MONDAY = 2;
    public const DOW_TUESDAY = 3;
    public const DOW_WEDNESDAY = 4;
    public const DOW_THURSDAY = 5;
    public const DOW_FRIDAY = 6;
    public const DOW_SATURDAY = 7;

    public const JANUARY = 0;
    public const FEBRUARY = 1;
    public const MARCH = 2;
    public const APRIL = 3;
    public const MAY = 4;
    public const JUNE = 5;
    public const JULY = 6;
    public const AUGUST = 7;
    public const SEPTEMBER = 8;
    public const OCTOBER = 9;
    public const NOVEMBER = 10;
    public const DECEMBER = 11;
    public const UNDECIMBER = 12;

    /** @var array<int, array{timezone: string, locale: string, timestamp: int, millisecond: int}> */
    private static array $state = [];

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'FIELD_ERA' => self::FIELD_ERA,
            'FIELD_YEAR' => self::FIELD_YEAR,
            'FIELD_MONTH' => self::FIELD_MONTH,
            'FIELD_WEEK_OF_YEAR' => self::FIELD_WEEK_OF_YEAR,
            'FIELD_WEEK_OF_MONTH' => self::FIELD_WEEK_OF_MONTH,
            'FIELD_DATE' => self::FIELD_DATE,
            'FIELD_DAY_OF_YEAR' => self::FIELD_DAY_OF_YEAR,
            'FIELD_DAY_OF_WEEK' => self::FIELD_DAY_OF_WEEK,
            'FIELD_DAY_OF_WEEK_IN_MONTH' => self::FIELD_DAY_OF_WEEK_IN_MONTH,
            'FIELD_AM_PM' => self::FIELD_AM_PM,
            'FIELD_HOUR' => self::FIELD_HOUR,
            'FIELD_HOUR_OF_DAY' => self::FIELD_HOUR_OF_DAY,
            'FIELD_MINUTE' => self::FIELD_MINUTE,
            'FIELD_SECOND' => self::FIELD_SECOND,
            'FIELD_MILLISECOND' => self::FIELD_MILLISECOND,
            'FIELD_ZONE_OFFSET' => self::FIELD_ZONE_OFFSET,
            'FIELD_DST_OFFSET' => self::FIELD_DST_OFFSET,
            'FIELD_YEAR_WOY' => self::FIELD_YEAR_WOY,
            'FIELD_DOW_LOCAL' => self::FIELD_DOW_LOCAL,
            'FIELD_EXTENDED_YEAR' => self::FIELD_EXTENDED_YEAR,
            'FIELD_JULIAN_DAY' => self::FIELD_JULIAN_DAY,
            'FIELD_MILLISECONDS_IN_DAY' => self::FIELD_MILLISECONDS_IN_DAY,
            'FIELD_IS_LEAP_MONTH' => self::FIELD_IS_LEAP_MONTH,
            'FIELD_FIELD_COUNT' => self::FIELD_FIELD_COUNT,
            'FIELD_DAY_OF_MONTH' => self::FIELD_DAY_OF_MONTH,
            'DOW_SUNDAY' => self::DOW_SUNDAY,
            'DOW_MONDAY' => self::DOW_MONDAY,
            'DOW_TUESDAY' => self::DOW_TUESDAY,
            'DOW_WEDNESDAY' => self::DOW_WEDNESDAY,
            'DOW_THURSDAY' => self::DOW_THURSDAY,
            'DOW_FRIDAY' => self::DOW_FRIDAY,
            'DOW_SATURDAY' => self::DOW_SATURDAY,
            'JANUARY' => self::JANUARY,
            'FEBRUARY' => self::FEBRUARY,
            'MARCH' => self::MARCH,
            'APRIL' => self::APRIL,
            'MAY' => self::MAY,
            'JUNE' => self::JUNE,
            'JULY' => self::JULY,
            'AUGUST' => self::AUGUST,
            'SEPTEMBER' => self::SEPTEMBER,
            'OCTOBER' => self::OCTOBER,
            'NOVEMBER' => self::NOVEMBER,
            'DECEMBER' => self::DECEMBER,
            'UNDECIMBER' => self::UNDECIMBER,
        ];
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('IntlCalendar');
        $entry->isInternal = true;
        foreach (self::classConstants() as $name => $value) {
            $lc = strtolower($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$lc] = $const;
            $entry->constNames[$lc] = $name;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $methods = [
            'createinstance' => [new IntlCalendarCreateInstance(), 'createInstance', $pubStatic],
            'get' => [new IntlCalendarGet(), 'get', $pub],
            'set' => [new IntlCalendarSet(), 'set', $pub],
            'gettimezone' => [new IntlCalendarGetTimeZone(), 'getTimeZone', $pub],
            'gettime' => [new IntlCalendarGetTime(), 'getTime', $pub],
            'settime' => [new IntlCalendarSetTime(), 'setTime', $pub],
        ];
        foreach ($methods as $lc => [$handler, $name, $vis]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $vis;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function isCalendarObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    public static function createInstance(
        Context $ctx,
        string $timezoneId,
        string $locale
    ): ObjectEntry {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "IntlCalendar" not found');
        }
        // Ensure IntlTimeZone is registered for getTimeZone().
        VmIntlTimeZone::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'timezone' => $timezoneId,
            'locale' => $locale,
            'timestamp' => VmDate::time(),
            'millisecond' => 0,
        ];
        IntlError::clear();

        return $object;
    }

    /**
     * @return int|false
     */
    public static function get(ObjectEntry $cal, int $field)
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_get: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        if ($field < 0 || $field >= self::FIELD_FIELD_COUNT) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_get: invalid field: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $tz = $state['timezone'];
        $ts = $state['timestamp'];
        $ms = $state['millisecond'];
        IntlError::clear();

        return match ($field) {
            self::FIELD_ERA => 1, // AD
            self::FIELD_YEAR, self::FIELD_EXTENDED_YEAR => (int) VmDateTimeNative::format($ts, 0, $tz, 'Y'),
            self::FIELD_MONTH => ((int) VmDateTimeNative::format($ts, 0, $tz, 'n')) - 1,
            self::FIELD_WEEK_OF_YEAR => (int) VmDateTimeNative::format($ts, 0, $tz, 'W'),
            self::FIELD_WEEK_OF_MONTH => (int) ceil(((int) VmDateTimeNative::format($ts, 0, $tz, 'j')) / 7),
            self::FIELD_DATE, self::FIELD_DAY_OF_MONTH => (int) VmDateTimeNative::format($ts, 0, $tz, 'j'),
            self::FIELD_DAY_OF_YEAR => (int) VmDateTimeNative::format($ts, 0, $tz, 'z') + 1,
            self::FIELD_DAY_OF_WEEK => ((int) VmDateTimeNative::format($ts, 0, $tz, 'w')) + 1,
            self::FIELD_DAY_OF_WEEK_IN_MONTH => (int) ceil(((int) VmDateTimeNative::format($ts, 0, $tz, 'j')) / 7),
            self::FIELD_AM_PM => ((int) VmDateTimeNative::format($ts, 0, $tz, 'G')) >= 12 ? 1 : 0,
            self::FIELD_HOUR => ((int) VmDateTimeNative::format($ts, 0, $tz, 'G')) % 12,
            self::FIELD_HOUR_OF_DAY => (int) VmDateTimeNative::format($ts, 0, $tz, 'G'),
            self::FIELD_MINUTE => (int) VmDateTimeNative::format($ts, 0, $tz, 'i'),
            self::FIELD_SECOND => (int) VmDateTimeNative::format($ts, 0, $tz, 's'),
            self::FIELD_MILLISECOND => $ms,
            self::FIELD_ZONE_OFFSET => VmDateTimeNative::timezoneOffsetSeconds($tz, $ts) * 1000,
            self::FIELD_DST_OFFSET => 0,
            self::FIELD_YEAR_WOY => (int) VmDateTimeNative::format($ts, 0, $tz, 'o'),
            self::FIELD_DOW_LOCAL => ((int) VmDateTimeNative::format($ts, 0, $tz, 'N')),
            self::FIELD_JULIAN_DAY => (int) floor($ts / 86400) + 2440588,
            self::FIELD_MILLISECONDS_IN_DAY => (
                ((int) VmDateTimeNative::format($ts, 0, $tz, 'G')) * 3600000
                + ((int) VmDateTimeNative::format($ts, 0, $tz, 'i')) * 60000
                + ((int) VmDateTimeNative::format($ts, 0, $tz, 's')) * 1000
                + $ms
            ),
            self::FIELD_IS_LEAP_MONTH => 0,
            default => false,
        };
    }

    public static function setField(ObjectEntry $cal, int $field, int $value): bool
    {
        $state = &self::$state[$cal->id];
        if (!isset($state)) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_set: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $parts = self::parts($state['timezone'], $state['timestamp'], $state['millisecond']);
        switch ($field) {
            case self::FIELD_YEAR:
            case self::FIELD_EXTENDED_YEAR:
                $parts['year'] = $value;
                break;
            case self::FIELD_MONTH:
                $parts['month'] = $value + 1; // ICU 0-based → 1-based
                break;
            case self::FIELD_DATE:
            case self::FIELD_DAY_OF_MONTH:
                $parts['day'] = $value;
                break;
            case self::FIELD_HOUR_OF_DAY:
                $parts['hour'] = $value;
                break;
            case self::FIELD_MINUTE:
                $parts['minute'] = $value;
                break;
            case self::FIELD_SECOND:
                $parts['second'] = $value;
                break;
            case self::FIELD_MILLISECOND:
                $parts['millisecond'] = max(0, min(999, $value));
                break;
            default:
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'intlcal_set: unsupported field in this compiler build: U_ILLEGAL_ARGUMENT_ERROR'
                );

                return false;
        }
        $parsed = VmDateTimeNative::parseDateTime(
            \sprintf(
                '%04d-%02d-%02d %02d:%02d:%02d',
                $parts['year'],
                $parts['month'],
                $parts['day'],
                $parts['hour'],
                $parts['minute'],
                $parts['second']
            ),
            $state['timezone']
        );
        $state['timestamp'] = $parsed['timestamp'];
        $state['millisecond'] = $parts['millisecond'];
        IntlError::clear();

        return true;
    }

    public static function setDate(
        ObjectEntry $cal,
        int $year,
        int $month,
        ?int $dayOfMonth,
        ?int $hour,
        ?int $minute,
        ?int $second
    ): bool {
        $state = &self::$state[$cal->id];
        if (!isset($state)) {
            return false;
        }
        $parts = self::parts($state['timezone'], $state['timestamp'], $state['millisecond']);
        $parts['year'] = $year;
        $parts['month'] = $month + 1; // ICU month
        if (null !== $dayOfMonth) {
            $parts['day'] = $dayOfMonth;
        }
        if (null !== $hour) {
            $parts['hour'] = $hour;
        }
        if (null !== $minute) {
            $parts['minute'] = $minute;
        }
        if (null !== $second) {
            $parts['second'] = $second;
        }
        $parsed = VmDateTimeNative::parseDateTime(
            \sprintf(
                '%04d-%02d-%02d %02d:%02d:%02d',
                $parts['year'],
                $parts['month'],
                $parts['day'],
                $parts['hour'],
                $parts['minute'],
                $parts['second']
            ),
            $state['timezone']
        );
        $state['timestamp'] = $parsed['timestamp'];
        IntlError::clear();

        return true;
    }

    public static function getTimeZoneObject(ObjectEntry $cal, Context $ctx): ObjectEntry|false
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            return false;
        }

        return VmIntlTimeZone::createFromId($ctx, $state['timezone']);
    }

    public static function getTime(ObjectEntry $cal): float|false
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            return false;
        }
        IntlError::clear();

        return ((float) $state['timestamp']) * 1000.0 + (float) $state['millisecond'];
    }

    public static function setTime(ObjectEntry $cal, float $millis): bool
    {
        $state = &self::$state[$cal->id];
        if (!isset($state)) {
            return false;
        }
        if ($millis < 0) {
            $sec = (int) ceil($millis / 1000.0);
            $ms = (int) round($millis - ($sec * 1000.0));
        } else {
            $sec = (int) floor($millis / 1000.0);
            $ms = (int) round($millis - ($sec * 1000.0));
        }
        if ($ms < 0) {
            --$sec;
            $ms += 1000;
        } elseif ($ms >= 1000) {
            $sec += intdiv($ms, 1000);
            $ms %= 1000;
        }
        $state['timestamp'] = $sec;
        $state['millisecond'] = $ms;
        IntlError::clear();

        return true;
    }

    /**
     * @return array{year: int, month: int, day: int, hour: int, minute: int, second: int, millisecond: int}
     */
    private static function parts(string $tz, int $ts, int $ms): array
    {
        return [
            'year' => (int) VmDateTimeNative::format($ts, 0, $tz, 'Y'),
            'month' => (int) VmDateTimeNative::format($ts, 0, $tz, 'n'),
            'day' => (int) VmDateTimeNative::format($ts, 0, $tz, 'j'),
            'hour' => (int) VmDateTimeNative::format($ts, 0, $tz, 'G'),
            'minute' => (int) VmDateTimeNative::format($ts, 0, $tz, 'i'),
            'second' => (int) VmDateTimeNative::format($ts, 0, $tz, 's'),
            'millisecond' => $ms,
        ];
    }
}

/** IntlCalendar::createInstance() — php-src intlcal_create_instance (#6151). */
final class IntlCalendarCreateInstance extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createInstance');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::createInstance() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        $timezone = VmDate::defaultTimezoneGet();
        if ($argc >= 1) {
            $timezone = VmIntlTimeZone::resolveTimezoneOperand(
                $frame->calledArgs[0],
                $frame->vmContext,
                'IntlCalendar::createInstance',
                0
            );
        }
        $locale = '';
        if ($argc >= 2) {
            $localeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $localeVar->type) {
                $locale = VmString::coerceStringBuiltinArg(
                    $localeVar,
                    'IntlCalendar::createInstance',
                    1,
                    'locale'
                );
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmIntlCalendar::createInstance($frame->vmContext, $timezone, $locale));
    }
}

/** IntlCalendar::get() — php-src intlcal_get (#6151). */
final class IntlCalendarGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('get');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::get() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::get() called on incompatible object');
        }
        $field = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::get', 1, 'field');
        $result = VmIntlCalendar::get($receiver->toObject(), $field);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }
}

/**
 * IntlCalendar::set() — field+value (argc=2) or year/month[/day[/hour[/minute[/second]]]] (#6151).
 *
 * php-src: ext/intl/calendar/calendar_methods.c — PHP_FUNCTION(intlcal_set)
 */
final class IntlCalendarSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('set');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // Instance + args
        $userArgc = max(0, $argc - 1);
        if ($userArgc < 2 || $userArgc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::set() expects between 2 and 6 arguments, %d given',
                $userArgc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::set() called on incompatible object');
        }
        $cal = $receiver->toObject();
        $a0 = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::set', 1, 'year');
        $a1 = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'IntlCalendar::set', 2, 'month');
        if (2 === $userArgc) {
            // field, value
            $ok = VmIntlCalendar::setField($cal, $a0, $a1);
        } else {
            $day = $userArgc >= 3
                ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[3], 'IntlCalendar::set', 3, 'dayOfMonth')
                : null;
            $hour = $userArgc >= 4
                ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[4], 'IntlCalendar::set', 4, 'hour')
                : null;
            $minute = $userArgc >= 5
                ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[5], 'IntlCalendar::set', 5, 'minute')
                : null;
            $second = $userArgc >= 6
                ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[6], 'IntlCalendar::set', 6, 'second')
                : null;
            $ok = VmIntlCalendar::setDate($cal, $a0, $a1, $day, $hour, $minute, $second);
        }
        if (null === $frame->returnVar) {
            return;
        }
        // php-src returns true (tentative); void in newer stubs — return true for 8.2 parity.
        $frame->returnVar->bool($ok);
    }
}

/** IntlCalendar::getTimeZone() — php-src intlcal_get_time_zone (#6151). */
final class IntlCalendarGetTimeZone extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTimeZone');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::getTimeZone() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getTimeZone() called on incompatible object');
        }
        $tz = VmIntlCalendar::getTimeZoneObject($receiver->toObject(), $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $tz) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($tz);
    }
}

/** IntlCalendar::getTime() — milliseconds since epoch (#6151). */
final class IntlCalendarGetTime extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::getTime() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getTime() called on incompatible object');
        }
        $result = VmIntlCalendar::getTime($receiver->toObject());
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->float($result);
    }
}

/** IntlCalendar::setTime() — milliseconds since epoch (#6151). */
final class IntlCalendarSetTime extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setTime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::setTime() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::setTime() called on incompatible object');
        }
        $millisArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER === $millisArg->type) {
            $millis = (float) $millisArg->toInt();
        } elseif (Variable::TYPE_FLOAT === $millisArg->type) {
            $millis = $millisArg->toFloat();
        } else {
            $millis = (float) VmIntlDateFormatter::coerceIntArg(
                $millisArg,
                'IntlCalendar::setTime',
                1,
                'timestamp'
            );
        }
        $ok = VmIntlCalendar::setTime($receiver->toObject(), $millis);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}
