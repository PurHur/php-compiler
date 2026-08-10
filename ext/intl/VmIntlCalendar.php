<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ClassConstName;
use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * IntlCalendar / IntlGregorianCalendar — Gregorian field get/set via zoneinfo
 * (php-src calendar_*; #6151, #20756, #20906).
 *
 * Subset: createInstance, get/set, getTimeZone, getTime/setTime, getType, getNow,
 * add/roll/clear/isSet/equals, toDateTime/fromDateTime, fieldDifference,
 * before/after, setDate/setDateTime/setTimeZone, field bounds, weekend, wall-time options (#20851, #20905),
 * getAvailableLocales + comparison/zone/min-max procedurals (#20897),
 * IntlGregorianCalendar + isLeapYear/getGregorianChange/createFromDate* (#20906).
 * ICU field constants match UCalendarDateFields (unicode/ucal.h).
 */
final class VmIntlCalendar
{
    public const CLASS_LC = 'intlcalendar';
    public const GREGORIAN_CLASS_LC = 'intlgregoriancalendar';

    /**
     * ICU GregorianCalendar default cutover (1582-10-15 00:00:00 UTC) in milliseconds
     * since Unix epoch — php-src / ICU getGregorianChange().
     */
    public const DEFAULT_GREGORIAN_CHANGE = -12219292800000.0;

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

    /** UCalendarWallTimeOption — php-src / ICU ucal.h */
    public const WALLTIME_LAST = 0;
    public const WALLTIME_FIRST = 1;
    public const WALLTIME_NEXT_VALID = 2;

    /** UCalendarWeekdayType */
    public const DOW_TYPE_WEEKDAY = 0;
    public const DOW_TYPE_WEEKEND = 1;
    public const DOW_TYPE_WEEKEND_ONSET = 2;
    public const DOW_TYPE_WEEKEND_CEASE = 3;

    /** ULOC_ACTUAL_LOCALE / ULOC_VALID_LOCALE — php-src Locale::* / intlcal_get_locale */
    public const ULOC_ACTUAL_LOCALE = 0;
    public const ULOC_VALID_LOCALE = 1;

    /**
     * @var array<int, array{
     *   timezone: string,
     *   locale: string,
     *   timestamp: int,
     *   millisecond: int,
     *   udate: float,
     *   unsetFields: array<int, true>,
     *   repeatedWallTimeOption: int,
     *   skippedWallTimeOption: int,
     *   lenient: bool,
     *   firstDayOfWeek: int,
     *   minimalDaysInFirstWeek: int,
     *   gregorianChange: float
     * }>
     */
    private static array $state = [];

    /**
     * Split ICU UDate (ms since epoch, may be fractional) into unix seconds +
     * integer FIELD_MILLISECOND — php-src calendar_methods.cpp / ICU UDate.
     *
     * @return array{0: int, 1: int}
     */
    private static function splitUDate(float $millis): array
    {
        $sec = (int) floor($millis / 1000.0);
        $ms = (int) floor($millis - ($sec * 1000.0));
        if ($ms >= 1000) {
            $sec += intdiv($ms, 1000);
            $ms %= 1000;
        } elseif ($ms < 0) {
            --$sec;
            $ms += 1000;
        }

        return [$sec, $ms];
    }

    /**
     * Store full float UDate and derive integer second / FIELD_MILLISECOND (#25788).
     *
     * @param array{timezone: string, locale: string, timestamp: int, millisecond: int, udate?: float, unsetFields: array<int, true>} $state
     */
    private static function applyUDate(array &$state, float $millis): void
    {
        $state['udate'] = $millis;
        [$sec, $ms] = self::splitUDate($millis);
        $state['timestamp'] = $sec;
        $state['millisecond'] = $ms;
    }

    /**
     * Rebuild UDate from integer fields (drops sub-millisecond fraction).
     *
     * @param array{timezone: string, locale: string, timestamp: int, millisecond: int, udate?: float, unsetFields: array<int, true>} $state
     */
    private static function resyncUDateFromFields(array &$state): void
    {
        $state['udate'] = ((float) $state['timestamp']) * 1000.0 + (float) $state['millisecond'];
    }

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
            'WALLTIME_LAST' => self::WALLTIME_LAST,
            'WALLTIME_FIRST' => self::WALLTIME_FIRST,
            'WALLTIME_NEXT_VALID' => self::WALLTIME_NEXT_VALID,
            'DOW_TYPE_WEEKDAY' => self::DOW_TYPE_WEEKDAY,
            'DOW_TYPE_WEEKEND' => self::DOW_TYPE_WEEKEND,
            'DOW_TYPE_WEEKEND_ONSET' => self::DOW_TYPE_WEEKEND_ONSET,
            'DOW_TYPE_WEEKEND_CEASE' => self::DOW_TYPE_WEEKEND_CEASE,
        ];
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('IntlCalendar');
        $entry->isInternal = true;
        // Exact Zend casing for defined()/hasConstant after #25910 (#29999 / #28132).
        foreach (self::classConstants() as $name => $value) {
            $key = ClassConstName::key($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$key] = $const;
            $entry->constNames[$key] = $name;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $methods = [
            'createinstance' => [new IntlCalendarCreateInstance(), 'createInstance', $pubStatic],
            'getnow' => [new IntlCalendarGetNow(), 'getNow', $pubStatic],
            'fromdatetime' => [new IntlCalendarFromDateTime(), 'fromDateTime', $pubStatic],
            'get' => [new IntlCalendarGet(), 'get', $pub],
            'set' => [new IntlCalendarSet(), 'set', $pub],
            'gettimezone' => [new IntlCalendarGetTimeZone(), 'getTimeZone', $pub],
            'gettime' => [new IntlCalendarGetTime(), 'getTime', $pub],
            'settime' => [new IntlCalendarSetTime(), 'setTime', $pub],
            'gettype' => [new IntlCalendarGetType(), 'getType', $pub],
            'add' => [new IntlCalendarAdd(), 'add', $pub],
            'roll' => [new IntlCalendarRoll(), 'roll', $pub],
            'clear' => [new IntlCalendarClear(), 'clear', $pub],
            'isset' => [new IntlCalendarIsSet(), 'isSet', $pub],
            'equals' => [new IntlCalendarEquals(), 'equals', $pub],
            'todatetime' => [new IntlCalendarToDateTime(), 'toDateTime', $pub],
            'fielddifference' => [new IntlCalendarFieldDifference(), 'fieldDifference', $pub],
            'before' => [new IntlCalendarBefore(), 'before', $pub],
            'after' => [new IntlCalendarAfter(), 'after', $pub],
            'settimezone' => [new IntlCalendarSetTimeZone(), 'setTimeZone', $pub],
            'getmaximum' => [new IntlCalendarGetMaximum(), 'getMaximum', $pub],
            'getminimum' => [new IntlCalendarGetMinimum(), 'getMinimum', $pub],
            'getactualmaximum' => [new IntlCalendarGetActualMaximum(), 'getActualMaximum', $pub],
            'getactualminimum' => [new IntlCalendarGetActualMinimum(), 'getActualMinimum', $pub],
            'isweekend' => [new IntlCalendarIsWeekend(), 'isWeekend', $pub],
            'isequivalentto' => [new IntlCalendarIsEquivalentTo(), 'isEquivalentTo', $pub],
            'getdayofweektype' => [new IntlCalendarGetDayOfWeekType(), 'getDayOfWeekType', $pub],
            'indaylighttime' => [new IntlCalendarInDaylightTime(), 'inDaylightTime', $pub],
            'getlocale' => [new IntlCalendarGetLocale(), 'getLocale', $pub],
            'islenient' => [new IntlCalendarIsLenient(), 'isLenient', $pub],
            'setlenient' => [new IntlCalendarSetLenient(), 'setLenient', $pub],
            'getfirstdayofweek' => [new IntlCalendarGetFirstDayOfWeek(), 'getFirstDayOfWeek', $pub],
            'setfirstdayofweek' => [new IntlCalendarSetFirstDayOfWeek(), 'setFirstDayOfWeek', $pub],
            'getminimaldaysinfirstweek' => [new IntlCalendarGetMinimalDaysInFirstWeek(), 'getMinimalDaysInFirstWeek', $pub],
            'setminimaldaysinfirstweek' => [new IntlCalendarSetMinimalDaysInFirstWeek(), 'setMinimalDaysInFirstWeek', $pub],
            'getweekendtransition' => [new IntlCalendarGetWeekendTransition(), 'getWeekendTransition', $pub],
            'getleastmaximum' => [new IntlCalendarGetLeastMaximum(), 'getLeastMaximum', $pub],
            'getgreatestminimum' => [new IntlCalendarGetGreatestMinimum(), 'getGreatestMinimum', $pub],
            'getkeywordvaluesforlocale' => [new IntlCalendarGetKeywordValuesForLocale(), 'getKeywordValuesForLocale', $pubStatic],
            'getavailablelocales' => [new IntlCalendarGetAvailableLocales(), 'getAvailableLocales', $pubStatic],
            'geterrorcode' => [new IntlCalendarGetErrorCode(), 'getErrorCode', $pub],
            'geterrormessage' => [new IntlCalendarGetErrorMessage(), 'getErrorMessage', $pub],
            'getrepeatedwalltimeoption' => [new IntlCalendarGetRepeatedWallTimeOption(), 'getRepeatedWallTimeOption', $pub],
            'setrepeatedwalltimeoption' => [new IntlCalendarSetRepeatedWallTimeOption(), 'setRepeatedWallTimeOption', $pub],
            'getskippedwalltimeoption' => [new IntlCalendarGetSkippedWallTimeOption(), 'getSkippedWallTimeOption', $pub],
            'setskippedwalltimeoption' => [new IntlCalendarSetSkippedWallTimeOption(), 'setSkippedWallTimeOption', $pub],
        ];
        // PHP 8.4+ only — Zend 8.2 method_exists false (#22597, re-#20851/#20905).
        if (CompilerVersion::supportsIntlCalendarSetDate()) {
            $methods['setdate'] = [new IntlCalendarSetDate(), 'setDate', $pub];
            $methods['setdatetime'] = [new IntlCalendarSetDateTime(), 'setDateTime', $pub];
        }
        foreach ($methods as $lc => [$handler, $name, $vis]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $vis;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes[self::CLASS_LC] = $entry;

        // IntlGregorianCalendar extends IntlCalendar (php-src calendar.stub.php; #20906).
        // createInstance() returns this concrete class (Zend parity).
        $greg = new ClassEntry('IntlGregorianCalendar');
        $greg->isInternal = true;
        $greg->parentLc = self::CLASS_LC;
        foreach (self::classConstants() as $name => $value) {
            $key = ClassConstName::key($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $greg->constants[$key] = $const;
            $greg->constNames[$key] = $name;
        }
        $construct = new IntlGregorianCalendarConstruct();
        $greg->constructor = $construct;
        $greg->methods['__construct'] = $construct;
        $greg->methodVisibility['__construct'] = $pub;
        $greg->methodNames['__construct'] = '__construct';
        $gregMethods = [
            'isleapyear' => [new IntlGregorianCalendarIsLeapYear(), 'isLeapYear', $pub],
            'getgregorianchange' => [new IntlGregorianCalendarGetGregorianChange(), 'getGregorianChange', $pub],
            'setgregorianchange' => [new IntlGregorianCalendarSetGregorianChange(), 'setGregorianChange', $pub],
        ];
        // php-src PHP-8.3+ OO only — withhold on reference / PROFILE=8.2 (#20906, #26745)
        if (CompilerVersion::supportsIntlGregorianCreateFromDate()) {
            $gregMethods['createfromdate'] = [new IntlGregorianCalendarCreateFromDate(), 'createFromDate', $pubStatic];
            $gregMethods['createfromdatetime'] = [new IntlGregorianCalendarCreateFromDateTime(), 'createFromDateTime', $pubStatic];
        }
        foreach ($gregMethods as $lc => [$handler, $name, $vis]) {
            $greg->methods[$lc] = $handler;
            $greg->methodVisibility[$lc] = $vis;
            $greg->methodNames[$lc] = $name;
        }
        $ctx->classes[self::GREGORIAN_CLASS_LC] = $greg;
    }

    public static function isCalendarObject(?ObjectEntry $object): bool
    {
        if (null === $object) {
            return false;
        }
        $lc = strtolower($object->class->name);

        return self::CLASS_LC === $lc
            || self::GREGORIAN_CLASS_LC === $lc
            || self::CLASS_LC === ($object->class->parentLc ?? '');
    }

    public static function isGregorianCalendarObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::GREGORIAN_CLASS_LC === strtolower($object->class->name);
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
        // Zend intlcal_create_instance returns IntlGregorianCalendar when available.
        $classLc = isset($ctx->classes[self::GREGORIAN_CLASS_LC])
            ? self::GREGORIAN_CLASS_LC
            : self::CLASS_LC;
        $object = new ObjectEntry($ctx->classes[$classLc]);
        self::initCalendarState($object, $timezoneId, $locale);

        return $object;
    }

    /**
     * Bind calendar state on a new or constructed object (createInstance / __construct).
     */
    public static function initCalendarState(
        ObjectEntry $object,
        string $timezoneId,
        string $locale
    ): void {
        $object->constructed = true;
        [$firstDow, $minDays] = self::localeWeekDefaults($locale);
        // ICU GregorianCalendar starts at "now" including sub-second UDate (#25190).
        $nowMs = (int) \floor(((float) VmDate::microtime(true)) * 1000.0);
        $ts = intdiv($nowMs, 1000);
        $ms = $nowMs % 1000;
        if ($ms < 0) {
            // PHP % can be negative for negative dividends; normalize to [0,999].
            $ms += 1000;
            --$ts;
        }
        self::$state[$object->id] = [
            'timezone' => $timezoneId,
            'locale' => $locale,
            'timestamp' => $ts,
            'millisecond' => $ms,
            'udate' => (float) $nowMs,
            'unsetFields' => [],
            'repeatedWallTimeOption' => self::WALLTIME_LAST,
            'skippedWallTimeOption' => self::WALLTIME_LAST,
            'lenient' => true,
            'firstDayOfWeek' => $firstDow,
            'minimalDaysInFirstWeek' => $minDays,
            'gregorianChange' => self::DEFAULT_GREGORIAN_CHANGE,
        ];
        IntlError::clear();
    }

    /** @return array{0: int, 1: int} firstDayOfWeek, minimalDaysInFirstWeek */
    private static function localeWeekDefaults(string $locale): array
    {
        $lc = strtolower(str_replace('-', '_', $locale));
        // ICU/CLDR: en_US (and similar) Sunday + 1; most European locales Monday + 4.
        if (str_starts_with($lc, 'en_us') || str_starts_with($lc, 'en_ca')
            || str_starts_with($lc, 'en_il') || str_starts_with($lc, 'ja')
            || str_starts_with($lc, 'ko') || str_starts_with($lc, 'zh_tw')) {
            return [self::DOW_SUNDAY, 1];
        }

        return [self::DOW_MONDAY, 4];
    }

    public static function getType(ObjectEntry $cal): string|false
    {
        if (!isset(self::$state[$cal->id])) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_get_type: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();

        return 'gregorian';
    }

    public static function getNow(): float
    {
        IntlError::clear();

        return ((float) VmDate::time()) * 1000.0;
    }

    public static function equals(ObjectEntry $a, ObjectEntry $b): bool
    {
        $ta = self::getTime($a);
        $tb = self::getTime($b);
        if (false === $ta || false === $tb) {
            return false;
        }

        return $ta === $tb;
    }

    public static function isSet(ObjectEntry $cal, int $field): bool
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            return false;
        }
        if ($field < 0 || $field >= self::FIELD_FIELD_COUNT) {
            return false;
        }

        return !isset($state['unsetFields'][$field]);
    }

    public static function clear(ObjectEntry $cal, ?int $field): bool
    {
        $state = &self::$state[$cal->id];
        if (!isset($state)) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_clear: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        if (null === $field) {
            $state['timestamp'] = 0;
            $state['millisecond'] = 0;
            self::resyncUDateFromFields($state);
            $state['unsetFields'] = [];
            for ($i = 0; $i < self::FIELD_FIELD_COUNT; ++$i) {
                // php-src/ICU: after clear(), YEAR is unset; month/day/hour remain set at epoch defaults.
                if (self::FIELD_YEAR === $i || self::FIELD_EXTENDED_YEAR === $i || self::FIELD_ERA === $i) {
                    $state['unsetFields'][$i] = true;
                }
            }
            IntlError::clear();

            return true;
        }
        if ($field < 0 || $field >= self::FIELD_FIELD_COUNT) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_clear: invalid field: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $state['unsetFields'][$field] = true;
        IntlError::clear();

        return true;
    }

    public static function add(ObjectEntry $cal, int $field, int $amount): bool
    {
        $state = &self::$state[$cal->id];
        if (!isset($state)) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_add: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        if (0 === $amount) {
            unset($state['unsetFields'][$field]);
            IntlError::clear();

            return true;
        }
        $tz = $state['timezone'];
        $parts = self::parts($tz, $state['timestamp'], $state['millisecond']);
        switch ($field) {
            case self::FIELD_YEAR:
            case self::FIELD_EXTENDED_YEAR:
                $parts['year'] += $amount;
                self::applyParts($state, $parts);
                break;
            case self::FIELD_MONTH:
                self::addMonths($parts, $amount);
                self::applyParts($state, $parts);
                break;
            case self::FIELD_DATE:
            case self::FIELD_DAY_OF_MONTH:
                $state['timestamp'] = self::addSecondsInZone($tz, $state['timestamp'], $amount * 86400);
                break;
            case self::FIELD_DAY_OF_YEAR:
            case self::FIELD_WEEK_OF_YEAR:
                $mult = self::FIELD_WEEK_OF_YEAR === $field ? 7 : 1;
                $state['timestamp'] = self::addSecondsInZone($tz, $state['timestamp'], $amount * $mult * 86400);
                break;
            case self::FIELD_HOUR:
            case self::FIELD_HOUR_OF_DAY:
                $state['timestamp'] = self::addSecondsInZone($tz, $state['timestamp'], $amount * 3600);
                break;
            case self::FIELD_MINUTE:
                $state['timestamp'] = self::addSecondsInZone($tz, $state['timestamp'], $amount * 60);
                break;
            case self::FIELD_SECOND:
                $state['timestamp'] = self::addSecondsInZone($tz, $state['timestamp'], $amount);
                break;
            case self::FIELD_MILLISECOND:
                self::addMilliseconds($state, $amount);
                break;
            default:
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'intlcal_add: unsupported field in this compiler build: U_ILLEGAL_ARGUMENT_ERROR'
                );

                return false;
        }
        self::resyncUDateFromFields($state);
        unset($state['unsetFields'][$field]);
        IntlError::clear();

        return true;
    }

    public static function roll(ObjectEntry $cal, int $field, int $amount): bool
    {
        $state = &self::$state[$cal->id];
        if (!isset($state)) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_roll: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        if (0 === $amount) {
            unset($state['unsetFields'][$field]);
            IntlError::clear();

            return true;
        }
        $tz = $state['timezone'];
        $parts = self::parts($tz, $state['timestamp'], $state['millisecond']);
        switch ($field) {
            case self::FIELD_YEAR:
            case self::FIELD_EXTENDED_YEAR:
                $parts['year'] += $amount;
                self::applyParts($state, $parts);
                break;
            case self::FIELD_MONTH:
                $parts['month'] = self::modRange($parts['month'] - 1 + $amount, 12) + 1;
                $dim = self::daysInMonth($parts['year'], $parts['month']);
                if ($parts['day'] > $dim) {
                    $parts['day'] = $dim;
                }
                self::applyParts($state, $parts);
                break;
            case self::FIELD_DATE:
            case self::FIELD_DAY_OF_MONTH:
                $dim = self::daysInMonth($parts['year'], $parts['month']);
                $parts['day'] = self::modRange($parts['day'] - 1 + $amount, $dim) + 1;
                self::applyParts($state, $parts);
                break;
            case self::FIELD_HOUR_OF_DAY:
                $parts['hour'] = self::modRange($parts['hour'] + $amount, 24);
                self::applyParts($state, $parts);
                break;
            case self::FIELD_HOUR:
                $h12 = $parts['hour'] % 12;
                $h12 = self::modRange($h12 + $amount, 12);
                $parts['hour'] = ($parts['hour'] >= 12 ? 12 : 0) + ($h12 % 12);
                if (24 === $parts['hour']) {
                    $parts['hour'] = 12;
                }
                self::applyParts($state, $parts);
                break;
            case self::FIELD_MINUTE:
                $parts['minute'] = self::modRange($parts['minute'] + $amount, 60);
                self::applyParts($state, $parts);
                break;
            case self::FIELD_SECOND:
                $parts['second'] = self::modRange($parts['second'] + $amount, 60);
                self::applyParts($state, $parts);
                break;
            case self::FIELD_MILLISECOND:
                $parts['millisecond'] = self::modRange($parts['millisecond'] + $amount, 1000);
                self::applyParts($state, $parts);
                break;
            default:
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'intlcal_roll: unsupported field in this compiler build: U_ILLEGAL_ARGUMENT_ERROR'
                );

                return false;
        }
        self::resyncUDateFromFields($state);
        unset($state['unsetFields'][$field]);
        IntlError::clear();

        return true;
    }

    /**
     * php-src intlcal_field_difference — advances calendar by the difference amount.
     *
     * @return int|false
     */
    public static function fieldDifference(ObjectEntry $cal, float $targetMs, int $field)
    {
        $before = self::getTime($cal);
        if (false === $before) {
            return false;
        }
        $deltaMs = $targetMs - $before;
        $amount = match ($field) {
            self::FIELD_MILLISECOND => (int) round($deltaMs),
            self::FIELD_SECOND => (int) round($deltaMs / 1000.0),
            self::FIELD_MINUTE => (int) round($deltaMs / 60000.0),
            self::FIELD_HOUR, self::FIELD_HOUR_OF_DAY => (int) round($deltaMs / 3600000.0),
            self::FIELD_DATE, self::FIELD_DAY_OF_MONTH, self::FIELD_DAY_OF_YEAR => (int) round($deltaMs / 86400000.0),
            self::FIELD_WEEK_OF_YEAR, self::FIELD_WEEK_OF_MONTH => (int) round($deltaMs / (7 * 86400000.0)),
            self::FIELD_MONTH => self::approxMonthDiff($cal, $targetMs),
            self::FIELD_YEAR, self::FIELD_EXTENDED_YEAR => self::approxYearDiff($cal, $targetMs),
            default => null,
        };
        if (null === $amount) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_field_difference: unsupported field in this compiler build: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        if (!self::add($cal, $field, $amount)) {
            return false;
        }
        IntlError::clear();

        return $amount;
    }

    public static function toDateTime(ObjectEntry $cal, Context $ctx): ObjectEntry|false
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_to_date_time: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $class = $ctx->classes[DateTimeSupport::CLASS_DATETIME] ?? null;
        if (null === $class) {
            throw new \LogicException('DateTime is not registered in this compiler build');
        }
        try {
            $entry = new ObjectEntry($class);
            DateTimeSupport::initDateTimeFromTimestamp($entry, $state['timestamp']);
            $zone = DateTimeSupport::newDateTimeZoneVariable($ctx, $state['timezone'])->toObject();
            DateTimeSupport::setTimezone($entry, $zone);
            IntlError::clear();

            return $entry;
        } catch (\Throwable) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_to_date_time: DateTime create failed: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
    }

    public static function fromDateTime(
        Context $ctx,
        ObjectEntry|string $datetime,
        ?string $locale
    ): ObjectEntry|false {
        if (\is_string($datetime)) {
            $class = $ctx->classes[DateTimeSupport::CLASS_DATETIME] ?? null;
            if (null === $class) {
                throw new \LogicException('DateTime is not registered in this compiler build');
            }
            try {
                $entry = new ObjectEntry($class);
                DateTimeSupport::initDateTime($entry, $datetime);
                $datetime = $entry;
            } catch (\Throwable) {
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'intlcal_from_date_time: bad datetime string: U_ILLEGAL_ARGUMENT_ERROR'
                );

                return false;
            }
        }
        $ts = DateTimeSupport::getTimestamp($datetime);
        $ms = intdiv(DateTimeSupport::getMicrosecond($datetime), 1000);
        $tz = DateTimeSupport::timezoneName(
            DateTimeSupport::getTimezoneObject($datetime, $ctx)
        );
        $cal = self::createInstance($ctx, $tz, $locale ?? '');
        self::$state[$cal->id]['timestamp'] = $ts;
        self::$state[$cal->id]['millisecond'] = $ms;
        self::resyncUDateFromFields(self::$state[$cal->id]);
        IntlError::clear();

        return $cal;
    }

    /**
     * @param array{timezone: string, locale: string, timestamp: int, millisecond: int, unsetFields: array<int, true>} $state
     * @param array{year: int, month: int, day: int, hour: int, minute: int, second: int, millisecond: int} $parts
     */
    private static function applyParts(array &$state, array $parts): void
    {
        $dim = self::daysInMonth($parts['year'], $parts['month']);
        if ($parts['day'] > $dim) {
            $parts['day'] = $dim;
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
        $state['millisecond'] = max(0, min(999, $parts['millisecond']));
        self::resyncUDateFromFields($state);
    }

    /**
     * @param array{year: int, month: int, day: int, hour: int, minute: int, second: int, millisecond: int} $parts
     */
    private static function addMonths(array &$parts, int $amount): void
    {
        $idx = ($parts['year'] * 12) + ($parts['month'] - 1) + $amount;
        $parts['year'] = intdiv($idx, 12);
        $parts['month'] = ($idx % 12) + 1;
        if ($parts['month'] <= 0) {
            $parts['month'] += 12;
            --$parts['year'];
        }
        $dim = self::daysInMonth($parts['year'], $parts['month']);
        if ($parts['day'] > $dim) {
            $parts['day'] = $dim;
        }
    }

    /**
     * @param array{timezone: string, locale: string, timestamp: int, millisecond: int, unsetFields: array<int, true>} $state
     */
    private static function addMilliseconds(array &$state, int $amount): void
    {
        $total = ($state['timestamp'] * 1000) + $state['millisecond'] + $amount;
        if ($total >= 0) {
            $state['timestamp'] = intdiv($total, 1000);
            $state['millisecond'] = $total % 1000;
        } else {
            $sec = (int) floor($total / 1000);
            $ms = $total - ($sec * 1000);
            $state['timestamp'] = $sec;
            $state['millisecond'] = $ms;
        }
        self::resyncUDateFromFields($state);
    }

    private static function addSecondsInZone(string $tz, int $timestamp, int $seconds): int
    {
        unset($tz); // Gregorian civil arithmetic via UTC epoch is sufficient for v1 zoneinfo calendars.

        return $timestamp + $seconds;
    }

    private static function daysInMonth(int $year, int $month): int
    {
        if ($month < 1 || $month > 12) {
            return 31;
        }
        if (\function_exists('cal_days_in_month')) {
            return (int) \cal_days_in_month(\CAL_GREGORIAN, $month, $year);
        }
        $parsed = VmDateTimeNative::parseDateTime(
            \sprintf('%04d-%02d-01 00:00:00', $year, $month),
            'UTC'
        );
        $nextMonth = $month + 1;
        $nextYear = $year;
        if ($nextMonth > 12) {
            $nextMonth = 1;
            ++$nextYear;
        }
        $next = VmDateTimeNative::parseDateTime(
            \sprintf('%04d-%02d-01 00:00:00', $nextYear, $nextMonth),
            'UTC'
        );

        return (int) (($next['timestamp'] - $parsed['timestamp']) / 86400);
    }

    private static function modRange(int $value, int $modulus): int
    {
        if ($modulus <= 0) {
            return 0;
        }
        $r = $value % $modulus;
        if ($r < 0) {
            $r += $modulus;
        }

        return $r;
    }

    private static function approxMonthDiff(ObjectEntry $cal, float $targetMs): int
    {
        $state = self::$state[$cal->id];
        $parts = self::parts($state['timezone'], $state['timestamp'], $state['millisecond']);
        $targetSec = (int) floor($targetMs / 1000.0);
        $tp = self::parts($state['timezone'], $targetSec, 0);

        return (($tp['year'] * 12) + $tp['month']) - (($parts['year'] * 12) + $parts['month']);
    }

    private static function approxYearDiff(ObjectEntry $cal, float $targetMs): int
    {
        $state = self::$state[$cal->id];
        $parts = self::parts($state['timezone'], $state['timestamp'], $state['millisecond']);
        $targetSec = (int) floor($targetMs / 1000.0);
        $tp = self::parts($state['timezone'], $targetSec, 0);

        return $tp['year'] - $parts['year'];
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
        self::resyncUDateFromFields($state);
        unset($state['unsetFields'][$field]);
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
        self::resyncUDateFromFields($state);
        $state['unsetFields'] = [];
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

        return (float) ($state['udate'] ?? (((float) $state['timestamp']) * 1000.0 + (float) $state['millisecond']));
    }

    public static function setTime(ObjectEntry $cal, float $millis): bool
    {
        $state = &self::$state[$cal->id];
        if (!isset($state)) {
            return false;
        }
        // Retain full float UDate (ICU); FIELD_MILLISECOND stays integer via floor split (#25788).
        self::applyUDate($state, $millis);
        $state['unsetFields'] = [];
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

    public static function before(ObjectEntry $a, ObjectEntry $b): bool
    {
        $ta = self::getTime($a);
        $tb = self::getTime($b);
        if (false === $ta || false === $tb) {
            return false;
        }

        return $ta < $tb;
    }

    public static function after(ObjectEntry $a, ObjectEntry $b): bool
    {
        $ta = self::getTime($a);
        $tb = self::getTime($b);
        if (false === $ta || false === $tb) {
            return false;
        }

        return $ta > $tb;
    }

    public static function isEquivalentTo(ObjectEntry $a, ObjectEntry $b): bool
    {
        $sa = self::$state[$a->id] ?? null;
        $sb = self::$state[$b->id] ?? null;
        if (null === $sa || null === $sb) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_is_equivalent_to: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();

        return $sa['timezone'] === $sb['timezone'];
    }

    public static function setTimeZoneId(ObjectEntry $cal, string $timezoneId): bool
    {
        $state = &self::$state[$cal->id];
        if (!isset($state)) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_set_time_zone: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $state['timezone'] = $timezoneId;
        IntlError::clear();

        return true;
    }

    /** @return int|false */
    public static function getMaximum(ObjectEntry $cal, int $field)
    {
        return self::fieldBound($cal, $field, true, false);
    }

    /** @return int|false */
    public static function getMinimum(ObjectEntry $cal, int $field)
    {
        return self::fieldBound($cal, $field, false, false);
    }

    /** @return int|false */
    public static function getActualMaximum(ObjectEntry $cal, int $field)
    {
        return self::fieldBound($cal, $field, true, true);
    }

    /** @return int|false */
    public static function getActualMinimum(ObjectEntry $cal, int $field)
    {
        return self::fieldBound($cal, $field, false, true);
    }

    /** @return int|false */
    private static function fieldBound(ObjectEntry $cal, int $field, bool $maximum, bool $actual)
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_get_maximum: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        if ($field < 0 || $field >= self::FIELD_FIELD_COUNT) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_get_maximum: invalid field: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();
        $parts = self::parts($state['timezone'], $state['timestamp'], $state['millisecond']);

        return match ($field) {
            self::FIELD_ERA => $maximum ? 1 : 0,
            self::FIELD_YEAR, self::FIELD_EXTENDED_YEAR, self::FIELD_YEAR_WOY => $maximum ? 292278994 : 1,
            self::FIELD_MONTH => $maximum ? 11 : 0,
            self::FIELD_WEEK_OF_YEAR => $maximum ? 53 : 1,
            self::FIELD_WEEK_OF_MONTH, self::FIELD_DAY_OF_WEEK_IN_MONTH => $maximum ? 5 : 0,
            self::FIELD_DATE, self::FIELD_DAY_OF_MONTH => $maximum
                ? ($actual ? self::daysInMonth($parts['year'], $parts['month']) : 31)
                : 1,
            self::FIELD_DAY_OF_YEAR => $maximum ? (($parts['year'] % 4 === 0 && ($parts['year'] % 100 !== 0 || $parts['year'] % 400 === 0)) ? 366 : 365) : 1,
            self::FIELD_DAY_OF_WEEK, self::FIELD_DOW_LOCAL => $maximum ? 7 : 1,
            self::FIELD_AM_PM => $maximum ? 1 : 0,
            self::FIELD_HOUR => $maximum ? 11 : 0,
            self::FIELD_HOUR_OF_DAY => $maximum ? 23 : 0,
            self::FIELD_MINUTE, self::FIELD_SECOND => $maximum ? 59 : 0,
            self::FIELD_MILLISECOND => $maximum ? 999 : 0,
            self::FIELD_ZONE_OFFSET => $maximum ? 50400000 : -50400000,
            self::FIELD_DST_OFFSET => $maximum ? 7200000 : 0,
            self::FIELD_JULIAN_DAY => $maximum ? 213503982 : 1,
            self::FIELD_MILLISECONDS_IN_DAY => $maximum ? 86399999 : 0,
            self::FIELD_IS_LEAP_MONTH => $maximum ? 1 : 0,
            default => false,
        };
    }

    public static function isWeekend(ObjectEntry $cal, ?float $timestampMs): bool
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_is_weekend: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        if (null === $timestampMs) {
            $ts = $state['timestamp'];
        } else {
            $ts = (int) floor($timestampMs / 1000.0);
        }
        $dow = ((int) VmDateTimeNative::format($ts, 0, $state['timezone'], 'w')) + 1;
        IntlError::clear();

        return self::DOW_SUNDAY === $dow || self::DOW_SATURDAY === $dow;
    }

    /** @return int|false */
    public static function getDayOfWeekType(ObjectEntry $cal, int $dayOfWeek)
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_get_day_of_week_type: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        if ($dayOfWeek < self::DOW_SUNDAY || $dayOfWeek > self::DOW_SATURDAY) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_get_day_of_week_type: invalid day of week: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();
        if (self::DOW_SUNDAY === $dayOfWeek || self::DOW_SATURDAY === $dayOfWeek) {
            return self::DOW_TYPE_WEEKEND;
        }

        return self::DOW_TYPE_WEEKDAY;
    }

    public static function inDaylightTime(ObjectEntry $cal): bool
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_in_daylight_time: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();
        $ts = (int) $state['timestamp'];
        $tz = (string) $state['timezone'];
        $offset = VmDateTimeNative::timezoneOffsetSeconds($tz, $ts);
        $year = (int) gmdate('Y', $ts);
        // Infer DST: offset strictly greater than the lesser of Jan/Jul reference offsets.
        $jan = VmDateTimeNative::timezoneOffsetSeconds($tz, gmmktime(12, 0, 0, 1, 15, $year));
        $jul = VmDateTimeNative::timezoneOffsetSeconds($tz, gmmktime(12, 0, 0, 7, 15, $year));
        $standard = min($jan, $jul);

        return $offset > $standard;
    }

    /** @return string|false */
    public static function getLocale(ObjectEntry $cal, int $type)
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_get_locale: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        if (self::ULOC_ACTUAL_LOCALE !== $type && self::ULOC_VALID_LOCALE !== $type) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_get_locale: invalid locale type: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();
        $locale = (string) $state['locale'];
        if (self::ULOC_VALID_LOCALE === $type) {
            return $locale;
        }
        // ACTUAL_LOCALE — ICU often returns the language subtag only (Zend: fr_FR → "fr").
        $parts = explode('_', str_replace('-', '_', $locale));

        return $parts[0] !== '' ? $parts[0] : $locale;
    }

    public static function isLenient(ObjectEntry $cal): bool
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            return true;
        }

        return (bool) $state['lenient'];
    }

    public static function setLenient(ObjectEntry $cal, bool $lenient): bool
    {
        $state = &self::$state[$cal->id];
        if (!isset($state)) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_set_lenient: bad calendar object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $state['lenient'] = $lenient;
        IntlError::clear();

        return true;
    }

    /** @return int|false */
    public static function getFirstDayOfWeek(ObjectEntry $cal)
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            return false;
        }
        IntlError::clear();

        return (int) $state['firstDayOfWeek'];
    }

    public static function setFirstDayOfWeek(ObjectEntry $cal, int $dayOfWeek): bool
    {
        $state = &self::$state[$cal->id];
        if (!isset($state)) {
            return false;
        }
        if ($dayOfWeek < self::DOW_SUNDAY || $dayOfWeek > self::DOW_SATURDAY) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_set_first_day_of_week: invalid day of week: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $state['firstDayOfWeek'] = $dayOfWeek;
        IntlError::clear();

        return true;
    }

    /** @return int|false */
    public static function getMinimalDaysInFirstWeek(ObjectEntry $cal)
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            return false;
        }
        IntlError::clear();

        return (int) $state['minimalDaysInFirstWeek'];
    }

    public static function setMinimalDaysInFirstWeek(ObjectEntry $cal, int $days): bool
    {
        $state = &self::$state[$cal->id];
        if (!isset($state)) {
            return false;
        }
        if ($days < 1 || $days > 7) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_set_minimal_days_in_first_week: invalid days: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $state['minimalDaysInFirstWeek'] = $days;
        IntlError::clear();

        return true;
    }

    /**
     * Milliseconds into the day when weekend onset/cease occurs for $dayOfWeek (ICU ucal_getWeekendTransition).
     *
     * @return int|false
     */
    public static function getWeekendTransition(ObjectEntry $cal, int $dayOfWeek)
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            return false;
        }
        if ($dayOfWeek < self::DOW_SUNDAY || $dayOfWeek > self::DOW_SATURDAY) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_get_weekend_transition: invalid day of week: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $type = self::getDayOfWeekType($cal, $dayOfWeek);
        if (self::DOW_TYPE_WEEKDAY === $type) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_get_weekend_transition: day is not a weekend transition day: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();
        // Gregorian Sat/Sun weekend: Saturday onset at midnight (0), Sunday cease at end of day.
        if (self::DOW_SATURDAY === $dayOfWeek) {
            return 0;
        }

        return 86400000;
    }

    /** @return int|false */
    public static function getLeastMaximum(ObjectEntry $cal, int $field)
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            return false;
        }
        if ($field < 0 || $field >= self::FIELD_FIELD_COUNT) {
            return false;
        }
        IntlError::clear();
        // Shortest absolute maximum (e.g. Feb → 28 for day-of-month).
        return match ($field) {
            self::FIELD_DATE, self::FIELD_DAY_OF_MONTH => 28,
            self::FIELD_DAY_OF_YEAR => 365,
            self::FIELD_WEEK_OF_YEAR => 52,
            self::FIELD_WEEK_OF_MONTH, self::FIELD_DAY_OF_WEEK_IN_MONTH => 4,
            default => self::getMaximum($cal, $field),
        };
    }

    /** @return int|false */
    public static function getGreatestMinimum(ObjectEntry $cal, int $field)
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            return false;
        }
        if ($field < 0 || $field >= self::FIELD_FIELD_COUNT) {
            return false;
        }
        IntlError::clear();

        return self::getMinimum($cal, $field);
    }

    /**
     * IntlCalendar::getKeywordValuesForLocale() — ICU calendar keyword catalog subset (#20873).
     * Returns IntlIterator|false (php-src calendar.stub.php / common_enum.cpp; #20909).
     *
     * @return ObjectEntry|false
     */
    public static function getKeywordValuesForLocale(
        Context $ctx,
        string $keyword,
        string $locale,
        bool $onlyCommon
    ): ObjectEntry|false {
        unset($locale);
        if ('calendar' !== strtolower($keyword)) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_get_keyword_values_for_locale: unsupported keyword: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();
        $common = ['gregorian'];
        if ($onlyCommon) {
            return VmIntlIterator::fromStringList($ctx, $common);
        }

        return VmIntlIterator::fromStringList($ctx, [
            'gregorian', 'japanese', 'buddhist', 'roc', 'persian', 'islamic', 'islamic-civil',
            'islamic-umalqura', 'hebrew', 'chinese', 'indian', 'coptic', 'ethiopic', 'ethiopic-amete-alem',
        ]);
    }

    /**
     * IntlCalendar::getAvailableLocales() — ICU Calendar::getAvailableLocales (#20897).
     *
     * Uses ResourceBundle ICU locale enumeration (same catalog as php-src as of ICU 51+).
     * Falls back to a non-empty curated subset when ICU FFI is unavailable.
     */
    public static function getAvailableLocales(): HashTable
    {
        IntlError::clear();
        $locales = VmResourceBundle::getLocales('');
        if (false === $locales || [] === $locales) {
            $locales = [
                'en', 'en_US', 'en_GB', 'de', 'de_DE', 'fr', 'fr_FR',
                'ja', 'ja_JP', 'zh', 'zh_CN', 'ar', 'ar_SA', 'root',
            ];
        }

        return VmFs::stringListToArray($locales);
    }

    public static function getErrorCode(ObjectEntry $cal): int|false
    {
        if (!isset(self::$state[$cal->id])) {
            return false;
        }

        return IntlError::getCode();
    }

    public static function getErrorMessage(ObjectEntry $cal): string|false
    {
        if (!isset(self::$state[$cal->id])) {
            return false;
        }
        $msg = IntlError::getMessage();

        return '' === $msg ? 'U_ZERO_ERROR' : $msg;
    }

    public static function getRepeatedWallTimeOption(ObjectEntry $cal): int
    {
        $state = self::$state[$cal->id] ?? null;

        return null === $state ? self::WALLTIME_LAST : $state['repeatedWallTimeOption'];
    }

    public static function setRepeatedWallTimeOption(ObjectEntry $cal, int $option): bool
    {
        $state = &self::$state[$cal->id];
        if (!isset($state)) {
            return false;
        }
        if ($option < self::WALLTIME_LAST || $option > self::WALLTIME_NEXT_VALID) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_set_repeated_wall_time_option: invalid option: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $state['repeatedWallTimeOption'] = $option;
        IntlError::clear();

        return true;
    }

    public static function getSkippedWallTimeOption(ObjectEntry $cal): int
    {
        $state = self::$state[$cal->id] ?? null;

        return null === $state ? self::WALLTIME_LAST : $state['skippedWallTimeOption'];
    }

    public static function setSkippedWallTimeOption(ObjectEntry $cal, int $option): bool
    {
        $state = &self::$state[$cal->id];
        if (!isset($state)) {
            return false;
        }
        if ($option < self::WALLTIME_LAST || $option > self::WALLTIME_NEXT_VALID) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intlcal_set_skipped_wall_time_option: invalid option: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $state['skippedWallTimeOption'] = $option;
        IntlError::clear();

        return true;
    }

    /**
     * Create IntlGregorianCalendar (or init $this) — php-src gregoriancalendar_methods.cpp (#20906).
     */
    public static function createGregorian(
        Context $ctx,
        string $timezoneId,
        string $locale,
        ?ObjectEntry $existing = null
    ): ObjectEntry {
        if (!isset($ctx->classes[self::GREGORIAN_CLASS_LC])) {
            throw new \Error('Class "IntlGregorianCalendar" not found');
        }
        VmIntlTimeZone::registerClass($ctx);
        $object = $existing ?? new ObjectEntry($ctx->classes[self::GREGORIAN_CLASS_LC]);
        if (isset(self::$state[$object->id])) {
            throw new \Error('IntlGregorianCalendar object is already constructed');
        }
        self::initCalendarState($object, $timezoneId, $locale);

        return $object;
    }

    public static function createGregorianFromDate(
        Context $ctx,
        int $year,
        int $month,
        int $dayOfMonth,
        ?int $hour = null,
        ?int $minute = null,
        ?int $second = null,
        ?ObjectEntry $existing = null
    ): ObjectEntry {
        $cal = self::createGregorian($ctx, VmDate::defaultTimezoneGet(), '', $existing);
        self::setDate($cal, $year, $month, $dayOfMonth, $hour, $minute, $second);

        return $cal;
    }

    /** Proleptic Gregorian leap-year rule (ICU GregorianCalendar::isLeapYear). */
    public static function isLeapYear(ObjectEntry $cal, int $year): bool
    {
        if (!isset(self::$state[$cal->id])) {
            return false;
        }
        IntlError::clear();

        return 0 === $year % 4 && (0 !== $year % 100 || 0 === $year % 400);
    }

    public static function getGregorianChange(ObjectEntry $cal): float
    {
        $state = self::$state[$cal->id] ?? null;
        if (null === $state) {
            return self::DEFAULT_GREGORIAN_CHANGE;
        }
        IntlError::clear();

        return (float) $state['gregorianChange'];
    }

    public static function setGregorianChange(ObjectEntry $cal, float $timestamp): bool
    {
        $state = &self::$state[$cal->id];
        if (!isset($state)) {
            return false;
        }
        $state['gregorianChange'] = $timestamp;
        IntlError::clear();

        return true;
    }

    /** Clamp year/month/day/... to ICU int32 range (php-src ZEND_VALUE_ERROR_OUT_OF_BOUND_VALUE). */
    public static function assertInt32Field(int $value, int $position, string $function): void
    {
        if ($value < -2147483648 || $value > 2147483647) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d must be between %d and %d',
                $function,
                $position,
                -2147483648,
                2147483647
            ));
        }
    }

    public static function coerceFloatArg(Variable $var, string $function, int $position, string $name): float
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_FLOAT === $var->type) {
            return $var->toFloat();
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return (float) $var->toInt();
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? 1.0 : 0.0;
        }
        if (Variable::TYPE_STRING === $var->type && is_numeric($var->toString())) {
            return (float) $var->toString();
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type float, %s given',
            $function,
            $position + 1,
            $name,
            ReflectionSupport::valueTypeLabelPublic($var)
        ));
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

/** IntlCalendar::getType() — php-src intlcal_get_type (#20756). */
final class IntlCalendarGetType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getType');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::getType() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getType() called on incompatible object');
        }
        $type = VmIntlCalendar::getType($receiver->toObject());
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $type) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($type);
    }
}

/** IntlCalendar::getNow() — php-src intlcal_get_now (#20756). */
final class IntlCalendarGetNow extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getNow');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::getNow() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmIntlCalendar::getNow());
    }
}

/** IntlCalendar::add() — php-src intlcal_add (#20756). */
final class IntlCalendarAdd extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('add');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::add() expects exactly 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::add() called on incompatible object');
        }
        $field = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::add', 1, 'field');
        $amount = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'IntlCalendar::add', 2, 'value');
        $ok = VmIntlCalendar::add($receiver->toObject(), $field, $amount);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** IntlCalendar::roll() — php-src intlcal_roll (#20756). */
final class IntlCalendarRoll extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('roll');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::roll() expects exactly 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::roll() called on incompatible object');
        }
        $field = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::roll', 1, 'field');
        $amount = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'IntlCalendar::roll', 2, 'value');
        $ok = VmIntlCalendar::roll($receiver->toObject(), $field, $amount);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** IntlCalendar::clear() — php-src intlcal_clear (#20756). */
final class IntlCalendarClear extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('clear');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $userArgc = max(0, $argc - 1);
        if ($userArgc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::clear() expects at most 1 argument, %d given',
                $userArgc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::clear() called on incompatible object');
        }
        $field = null;
        if (2 === $argc) {
            $field = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::clear', 1, 'field');
        }
        $ok = VmIntlCalendar::clear($receiver->toObject(), $field);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** IntlCalendar::isSet() — php-src intlcal_is_set (#20756). */
final class IntlCalendarIsSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isSet');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::isSet() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::isSet() called on incompatible object');
        }
        $field = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::isSet', 1, 'field');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlCalendar::isSet($receiver->toObject(), $field));
    }
}

/** IntlCalendar::equals() — php-src intlcal_equals (#20756). */
final class IntlCalendarEquals extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('equals');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::equals() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::equals() called on incompatible object');
        }
        $other = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $other->type
            || !VmIntlCalendar::isCalendarObject($other->toObject())) {
            throw new \TypeError('IntlCalendar::equals(): Argument #1 ($other) must be of type IntlCalendar, '
                .\PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($other).' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlCalendar::equals($receiver->toObject(), $other->toObject()));
    }
}

/** IntlCalendar::toDateTime() — php-src intlcal_to_date_time (#20756). */
final class IntlCalendarToDateTime extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('toDateTime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::toDateTime() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::toDateTime() called on incompatible object');
        }
        $dt = VmIntlCalendar::toDateTime($receiver->toObject(), $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $dt) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($dt);
    }
}

/** IntlCalendar::fromDateTime() — php-src intlcal_from_date_time (#20756). */
final class IntlCalendarFromDateTime extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fromDateTime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::fromDateTime() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $arg0 = $frame->calledArgs[0]->resolveIndirect();
        $datetime = null;
        if (Variable::TYPE_OBJECT === $arg0->type) {
            $datetime = DateTimeSupport::requireDateTime(
                $arg0,
                'IntlCalendar::fromDateTime',
                1,
                'datetime',
                $frame->vmContext
            );
        } elseif (Variable::TYPE_STRING === $arg0->type) {
            $datetime = $arg0->toString();
        } else {
            $datetime = VmString::coerceStringBuiltinArg(
                $arg0,
                'IntlCalendar::fromDateTime',
                1,
                'datetime'
            );
        }
        $locale = null;
        if ($argc >= 2) {
            $localeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $localeVar->type) {
                $locale = VmString::coerceStringBuiltinArg(
                    $localeVar,
                    'IntlCalendar::fromDateTime',
                    2,
                    'locale'
                );
            }
        }
        $cal = VmIntlCalendar::fromDateTime($frame->vmContext, $datetime, $locale);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $cal) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($cal);
    }
}

/** IntlCalendar::fieldDifference() — php-src intlcal_field_difference (#20756). */
final class IntlCalendarFieldDifference extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fieldDifference');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::fieldDifference() expects exactly 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::fieldDifference() called on incompatible object');
        }
        $tsArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER === $tsArg->type) {
            $targetMs = (float) $tsArg->toInt();
        } elseif (Variable::TYPE_FLOAT === $tsArg->type) {
            $targetMs = $tsArg->toFloat();
        } else {
            $targetMs = (float) VmIntlDateFormatter::coerceIntArg(
                $tsArg,
                'IntlCalendar::fieldDifference',
                1,
                'timestamp'
            );
        }
        $field = VmIntlDateFormatter::coerceIntArg(
            $frame->calledArgs[2],
            'IntlCalendar::fieldDifference',
            2,
            'field'
        );
        $result = VmIntlCalendar::fieldDifference($receiver->toObject(), $targetMs, $field);
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

/** IntlCalendar::before() — php-src intlcal_before (#20851). */
final class IntlCalendarBefore extends VmClassMethod
{
    public function __construct() { parent::__construct('before'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::before() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::before() called on incompatible object');
        }
        $other = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $other->type || !VmIntlCalendar::isCalendarObject($other->toObject())) {
            throw new \TypeError('IntlCalendar::before(): Argument #1 ($other) must be of type IntlCalendar, '.\PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($other).' given');
        }
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->bool(VmIntlCalendar::before($receiver->toObject(), $other->toObject()));
    }
}

/** IntlCalendar::after() — php-src intlcal_after (#20851). */
final class IntlCalendarAfter extends VmClassMethod
{
    public function __construct() { parent::__construct('after'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::after() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::after() called on incompatible object');
        }
        $other = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $other->type || !VmIntlCalendar::isCalendarObject($other->toObject())) {
            throw new \TypeError('IntlCalendar::after(): Argument #1 ($other) must be of type IntlCalendar, '.\PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($other).' given');
        }
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->bool(VmIntlCalendar::after($receiver->toObject(), $other->toObject()));
    }
}

/** IntlCalendar::setDate() — php-src IntlCalendar::setDate (#20851). */
final class IntlCalendarSetDate extends VmClassMethod
{
    public function __construct() { parent::__construct('setDate'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (4 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::setDate() expects exactly 3 arguments, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::setDate() called on incompatible object');
        }
        $year = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::setDate', 1, 'year');
        $month = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'IntlCalendar::setDate', 2, 'month');
        $day = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[3], 'IntlCalendar::setDate', 3, 'dayOfMonth');
        VmIntlCalendar::setDate($receiver->toObject(), $year, $month, $day, null, null, null);
    }
}

/**
 * IntlCalendar::setDateTime() — php-src calendar.stub.php / calendar_methods.cpp (#20905).
 * Optional/?null $second uses ICU 5-arg set (second becomes 0), not leave-existing.
 */
final class IntlCalendarSetDateTime extends VmClassMethod
{
    public function __construct() { parent::__construct('setDateTime'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $userArgc = max(0, $argc - 1);
        if ($userArgc < 5 || $userArgc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'IntlCalendar::setDateTime() expects between 5 and 6 arguments, %d given',
                $userArgc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::setDateTime() called on incompatible object');
        }
        $year = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::setDateTime', 1, 'year');
        $month = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'IntlCalendar::setDateTime', 2, 'month');
        $day = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[3], 'IntlCalendar::setDateTime', 3, 'dayOfMonth');
        $hour = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[4], 'IntlCalendar::setDateTime', 4, 'hour');
        $minute = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[5], 'IntlCalendar::setDateTime', 5, 'minute');
        // php-src: null/omitted → ucal->set(y,m,d,h,i) (second 0); explicit int → 6-arg set.
        $second = 0;
        if ($userArgc >= 6) {
            $secondVar = $frame->calledArgs[6]->resolveIndirect();
            if (Variable::TYPE_NULL !== $secondVar->type) {
                $second = VmIntlDateFormatter::coerceIntArg($secondVar, 'IntlCalendar::setDateTime', 6, 'second');
            }
        }
        VmIntlCalendar::setDate($receiver->toObject(), $year, $month, $day, $hour, $minute, $second);
    }
}

/** IntlCalendar::setTimeZone() — php-src intlcal_set_time_zone (#20851). */
final class IntlCalendarSetTimeZone extends VmClassMethod
{
    public function __construct() { parent::__construct('setTimeZone'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::setTimeZone() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::setTimeZone() called on incompatible object');
        }
        $tz = VmIntlTimeZone::resolveTimezoneOperand($frame->calledArgs[1], $frame->vmContext, 'IntlCalendar::setTimeZone', 1);
        $ok = VmIntlCalendar::setTimeZoneId($receiver->toObject(), $tz);
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->bool($ok);
    }
}

/** IntlCalendar::getMaximum() — php-src intlcal_get_maximum (#20851). */
final class IntlCalendarGetMaximum extends VmClassMethod
{
    public function __construct() { parent::__construct('getMaximum'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getMaximum() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getMaximum() called on incompatible object');
        }
        $field = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::getMaximum', 1, 'field');
        $result = VmIntlCalendar::getMaximum($receiver->toObject(), $field);
        if (null === $frame->returnVar) { return; }
        if (false === $result) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->int($result);
    }
}

/** IntlCalendar::getMinimum() — php-src intlcal_get_minimum (#20851). */
final class IntlCalendarGetMinimum extends VmClassMethod
{
    public function __construct() { parent::__construct('getMinimum'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getMinimum() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getMinimum() called on incompatible object');
        }
        $field = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::getMinimum', 1, 'field');
        $result = VmIntlCalendar::getMinimum($receiver->toObject(), $field);
        if (null === $frame->returnVar) { return; }
        if (false === $result) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->int($result);
    }
}

/** IntlCalendar::getActualMaximum() — php-src intlcal_get_actual_maximum (#20851). */
final class IntlCalendarGetActualMaximum extends VmClassMethod
{
    public function __construct() { parent::__construct('getActualMaximum'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getActualMaximum() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getActualMaximum() called on incompatible object');
        }
        $field = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::getActualMaximum', 1, 'field');
        $result = VmIntlCalendar::getActualMaximum($receiver->toObject(), $field);
        if (null === $frame->returnVar) { return; }
        if (false === $result) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->int($result);
    }
}

/** IntlCalendar::getActualMinimum() — php-src intlcal_get_actual_minimum (#20851). */
final class IntlCalendarGetActualMinimum extends VmClassMethod
{
    public function __construct() { parent::__construct('getActualMinimum'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getActualMinimum() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getActualMinimum() called on incompatible object');
        }
        $field = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::getActualMinimum', 1, 'field');
        $result = VmIntlCalendar::getActualMinimum($receiver->toObject(), $field);
        if (null === $frame->returnVar) { return; }
        if (false === $result) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->int($result);
    }
}

/** IntlCalendar::isWeekend() — php-src intlcal_is_weekend (#20851). */
final class IntlCalendarIsWeekend extends VmClassMethod
{
    public function __construct() { parent::__construct('isWeekend'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $userArgc = max(0, $argc - 1);
        if ($userArgc > 1) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::isWeekend() expects at most 1 argument, %d given', $userArgc));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::isWeekend() called on incompatible object');
        }
        $timestampMs = null;
        if (2 === $argc) {
            $tsArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $tsArg->type) {
                if (Variable::TYPE_INTEGER === $tsArg->type) {
                    $timestampMs = (float) $tsArg->toInt();
                } elseif (Variable::TYPE_FLOAT === $tsArg->type) {
                    $timestampMs = $tsArg->toFloat();
                } else {
                    $timestampMs = (float) VmIntlDateFormatter::coerceIntArg($tsArg, 'IntlCalendar::isWeekend', 1, 'timestamp');
                }
            }
        }
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->bool(VmIntlCalendar::isWeekend($receiver->toObject(), $timestampMs));
    }
}

/** IntlCalendar::isEquivalentTo() — php-src intlcal_is_equivalent_to (#20851). */
final class IntlCalendarIsEquivalentTo extends VmClassMethod
{
    public function __construct() { parent::__construct('isEquivalentTo'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::isEquivalentTo() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::isEquivalentTo() called on incompatible object');
        }
        $other = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $other->type || !VmIntlCalendar::isCalendarObject($other->toObject())) {
            throw new \TypeError('IntlCalendar::isEquivalentTo(): Argument #1 ($other) must be of type IntlCalendar, '.\PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($other).' given');
        }
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->bool(VmIntlCalendar::isEquivalentTo($receiver->toObject(), $other->toObject()));
    }
}

/** IntlCalendar::getDayOfWeekType() — php-src intlcal_get_day_of_week_type (#20851). */
final class IntlCalendarGetDayOfWeekType extends VmClassMethod
{
    public function __construct() { parent::__construct('getDayOfWeekType'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getDayOfWeekType() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getDayOfWeekType() called on incompatible object');
        }
        $dow = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::getDayOfWeekType', 1, 'dayOfWeek');
        $result = VmIntlCalendar::getDayOfWeekType($receiver->toObject(), $dow);
        if (null === $frame->returnVar) { return; }
        if (false === $result) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->int($result);
    }
}

/** IntlCalendar::getErrorCode() — php-src intlcal_get_error_code (#20851). */
final class IntlCalendarGetErrorCode extends VmClassMethod
{
    public function __construct() { parent::__construct('getErrorCode'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getErrorCode() expects exactly 0 arguments, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getErrorCode() called on incompatible object');
        }
        $code = VmIntlCalendar::getErrorCode($receiver->toObject());
        if (null === $frame->returnVar) { return; }
        if (false === $code) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->int($code);
    }
}

/** IntlCalendar::getErrorMessage() — php-src intlcal_get_error_message (#20851). */
final class IntlCalendarGetErrorMessage extends VmClassMethod
{
    public function __construct() { parent::__construct('getErrorMessage'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getErrorMessage() expects exactly 0 arguments, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getErrorMessage() called on incompatible object');
        }
        $msg = VmIntlCalendar::getErrorMessage($receiver->toObject());
        if (null === $frame->returnVar) { return; }
        if (false === $msg) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->string($msg);
    }
}

/** IntlCalendar::getRepeatedWallTimeOption() — php-src intlcal_get_repeated_wall_time_option (#20851). */
final class IntlCalendarGetRepeatedWallTimeOption extends VmClassMethod
{
    public function __construct() { parent::__construct('getRepeatedWallTimeOption'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getRepeatedWallTimeOption() expects exactly 0 arguments, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getRepeatedWallTimeOption() called on incompatible object');
        }
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->int(VmIntlCalendar::getRepeatedWallTimeOption($receiver->toObject()));
    }
}

/** IntlCalendar::setRepeatedWallTimeOption() — php-src intlcal_set_repeated_wall_time_option (#20851). */
final class IntlCalendarSetRepeatedWallTimeOption extends VmClassMethod
{
    public function __construct() { parent::__construct('setRepeatedWallTimeOption'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::setRepeatedWallTimeOption() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::setRepeatedWallTimeOption() called on incompatible object');
        }
        $option = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::setRepeatedWallTimeOption', 1, 'option');
        $ok = VmIntlCalendar::setRepeatedWallTimeOption($receiver->toObject(), $option);
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->bool($ok);
    }
}

/** IntlCalendar::getSkippedWallTimeOption() — php-src intlcal_get_skipped_wall_time_option (#20851). */
final class IntlCalendarGetSkippedWallTimeOption extends VmClassMethod
{
    public function __construct() { parent::__construct('getSkippedWallTimeOption'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getSkippedWallTimeOption() expects exactly 0 arguments, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getSkippedWallTimeOption() called on incompatible object');
        }
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->int(VmIntlCalendar::getSkippedWallTimeOption($receiver->toObject()));
    }
}

/** IntlCalendar::setSkippedWallTimeOption() — php-src intlcal_set_skipped_wall_time_option (#20851). */
final class IntlCalendarSetSkippedWallTimeOption extends VmClassMethod
{
    public function __construct() { parent::__construct('setSkippedWallTimeOption'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::setSkippedWallTimeOption() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::setSkippedWallTimeOption() called on incompatible object');
        }
        $option = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::setSkippedWallTimeOption', 1, 'option');
        $ok = VmIntlCalendar::setSkippedWallTimeOption($receiver->toObject(), $option);
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->bool($ok);
    }
}

/** IntlCalendar::inDaylightTime() — php-src intlcal_in_daylight_time (#20873). */
final class IntlCalendarInDaylightTime extends VmClassMethod
{
    public function __construct() { parent::__construct('inDaylightTime'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::inDaylightTime() expects exactly 0 arguments, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::inDaylightTime() called on incompatible object');
        }
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->bool(VmIntlCalendar::inDaylightTime($receiver->toObject()));
    }
}

/** IntlCalendar::getLocale() — php-src intlcal_get_locale (#20873). */
final class IntlCalendarGetLocale extends VmClassMethod
{
    public function __construct() { parent::__construct('getLocale'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getLocale() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getLocale() called on incompatible object');
        }
        $type = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::getLocale', 1, 'localeType');
        $result = VmIntlCalendar::getLocale($receiver->toObject(), $type);
        if (null === $frame->returnVar) { return; }
        if (false === $result) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->string($result);
    }
}

/** IntlCalendar::isLenient() — php-src intlcal_is_lenient (#20873). */
final class IntlCalendarIsLenient extends VmClassMethod
{
    public function __construct() { parent::__construct('isLenient'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::isLenient() expects exactly 0 arguments, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::isLenient() called on incompatible object');
        }
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->bool(VmIntlCalendar::isLenient($receiver->toObject()));
    }
}

/** IntlCalendar::setLenient() — php-src intlcal_set_lenient (#20873). */
final class IntlCalendarSetLenient extends VmClassMethod
{
    public function __construct() { parent::__construct('setLenient'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::setLenient() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::setLenient() called on incompatible object');
        }
        $lenientVar = $frame->calledArgs[1]->resolveIndirect();
        $lenient = Variable::TYPE_NULL !== $lenientVar->type && $lenientVar->toBool();
        $ok = VmIntlCalendar::setLenient($receiver->toObject(), $lenient);
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->bool($ok);
    }
}

/** IntlCalendar::getFirstDayOfWeek() — php-src intlcal_get_first_day_of_week (#20873). */
final class IntlCalendarGetFirstDayOfWeek extends VmClassMethod
{
    public function __construct() { parent::__construct('getFirstDayOfWeek'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getFirstDayOfWeek() expects exactly 0 arguments, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getFirstDayOfWeek() called on incompatible object');
        }
        $result = VmIntlCalendar::getFirstDayOfWeek($receiver->toObject());
        if (null === $frame->returnVar) { return; }
        if (false === $result) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->int($result);
    }
}

/** IntlCalendar::setFirstDayOfWeek() — php-src intlcal_set_first_day_of_week (#20873). */
final class IntlCalendarSetFirstDayOfWeek extends VmClassMethod
{
    public function __construct() { parent::__construct('setFirstDayOfWeek'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::setFirstDayOfWeek() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::setFirstDayOfWeek() called on incompatible object');
        }
        $day = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::setFirstDayOfWeek', 1, 'dayOfWeek');
        $ok = VmIntlCalendar::setFirstDayOfWeek($receiver->toObject(), $day);
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->bool($ok);
    }
}

/** IntlCalendar::getMinimalDaysInFirstWeek() — php-src intlcal_get_minimal_days_in_first_week (#20873). */
final class IntlCalendarGetMinimalDaysInFirstWeek extends VmClassMethod
{
    public function __construct() { parent::__construct('getMinimalDaysInFirstWeek'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getMinimalDaysInFirstWeek() expects exactly 0 arguments, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getMinimalDaysInFirstWeek() called on incompatible object');
        }
        $result = VmIntlCalendar::getMinimalDaysInFirstWeek($receiver->toObject());
        if (null === $frame->returnVar) { return; }
        if (false === $result) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->int($result);
    }
}

/** IntlCalendar::setMinimalDaysInFirstWeek() — php-src intlcal_set_minimal_days_in_first_week (#20873). */
final class IntlCalendarSetMinimalDaysInFirstWeek extends VmClassMethod
{
    public function __construct() { parent::__construct('setMinimalDaysInFirstWeek'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::setMinimalDaysInFirstWeek() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::setMinimalDaysInFirstWeek() called on incompatible object');
        }
        $days = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::setMinimalDaysInFirstWeek', 1, 'minimalDays');
        $ok = VmIntlCalendar::setMinimalDaysInFirstWeek($receiver->toObject(), $days);
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->bool($ok);
    }
}

/** IntlCalendar::getWeekendTransition() — php-src intlcal_get_weekend_transition (#20873). */
final class IntlCalendarGetWeekendTransition extends VmClassMethod
{
    public function __construct() { parent::__construct('getWeekendTransition'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getWeekendTransition() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getWeekendTransition() called on incompatible object');
        }
        $day = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::getWeekendTransition', 1, 'dayOfWeek');
        $result = VmIntlCalendar::getWeekendTransition($receiver->toObject(), $day);
        if (null === $frame->returnVar) { return; }
        if (false === $result) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->int($result);
    }
}

/** IntlCalendar::getLeastMaximum() — php-src intlcal_get_least_maximum (#20873). */
final class IntlCalendarGetLeastMaximum extends VmClassMethod
{
    public function __construct() { parent::__construct('getLeastMaximum'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getLeastMaximum() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getLeastMaximum() called on incompatible object');
        }
        $field = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::getLeastMaximum', 1, 'field');
        $result = VmIntlCalendar::getLeastMaximum($receiver->toObject(), $field);
        if (null === $frame->returnVar) { return; }
        if (false === $result) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->int($result);
    }
}

/** IntlCalendar::getGreatestMinimum() — php-src intlcal_get_greatest_minimum (#20873). */
final class IntlCalendarGetGreatestMinimum extends VmClassMethod
{
    public function __construct() { parent::__construct('getGreatestMinimum'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getGreatestMinimum() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \Error('IntlCalendar::getGreatestMinimum() called on incompatible object');
        }
        $field = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlCalendar::getGreatestMinimum', 1, 'field');
        $result = VmIntlCalendar::getGreatestMinimum($receiver->toObject(), $field);
        if (null === $frame->returnVar) { return; }
        if (false === $result) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->int($result);
    }
}

/** IntlCalendar::getKeywordValuesForLocale() — php-src intlcal_get_keyword_values_for_locale (#20873). */
final class IntlCalendarGetKeywordValuesForLocale extends VmClassMethod
{
    public function __construct() { parent::__construct('getKeywordValuesForLocale'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getKeywordValuesForLocale() expects exactly 3 arguments, %d given', $argc));
        }
        $keyword = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'IntlCalendar::getKeywordValuesForLocale', 1, 'key');
        $locale = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'IntlCalendar::getKeywordValuesForLocale', 2, 'locale');
        $onlyCommonVar = $frame->calledArgs[2]->resolveIndirect();
        $onlyCommon = Variable::TYPE_NULL !== $onlyCommonVar->type && $onlyCommonVar->toBool();
        $result = VmIntlCalendar::getKeywordValuesForLocale($frame->vmContext, $keyword, $locale, $onlyCommon);
        if (null === $frame->returnVar) { return; }
        if (false === $result) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->object($result);
    }
}

/** IntlCalendar::getAvailableLocales() — php-src intlcal_get_available_locales (#20897). */
final class IntlCalendarGetAvailableLocales extends VmClassMethod
{
    public function __construct() { parent::__construct('getAvailableLocales'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlCalendar::getAvailableLocales() expects exactly 0 arguments, %d given', $argc));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmIntlCalendar::getAvailableLocales());
    }
}

/** IntlGregorianCalendar::__construct() — php-src gregoriancalendar_methods.cpp (#20906). */
final class IntlGregorianCalendarConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('IntlGregorianCalendar::__construct() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isGregorianCalendarObject($receiver->toObject())) {
            throw new \TypeError('IntlGregorianCalendar::__construct() must be called on IntlGregorianCalendar');
        }
        $userArgc = $argc - 1;
        while ($userArgc > 0
            && Variable::TYPE_NULL === $frame->calledArgs[$userArgc]->resolveIndirect()->type) {
            --$userArgc;
        }
        if (4 === $userArgc) {
            throw new \ArgumentCountError(
                'IntlGregorianCalendar::__construct(): No variant with 4 arguments (excluding trailing NULLs)'
            );
        }
        if ($userArgc > 6) {
            throw new \ArgumentCountError('IntlGregorianCalendar::__construct(): Too many arguments');
        }
        $obj = $receiver->toObject();
        $ctx = $frame->vmContext;
        if ($userArgc <= 2) {
            $timezone = VmDate::defaultTimezoneGet();
            if ($userArgc >= 1) {
                $timezone = VmIntlTimeZone::resolveTimezoneOperand(
                    $frame->calledArgs[1],
                    $ctx,
                    'IntlGregorianCalendar::__construct',
                    0
                );
            }
            $locale = '';
            if ($userArgc >= 2) {
                $localeVar = $frame->calledArgs[2]->resolveIndirect();
                if (Variable::TYPE_NULL !== $localeVar->type) {
                    $locale = VmString::coerceStringBuiltinArg(
                        $localeVar,
                        'IntlGregorianCalendar::__construct',
                        1,
                        'locale'
                    );
                }
            }
            VmIntlCalendar::createGregorian($ctx, $timezone, $locale, $obj);

            return;
        }
        $year = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlGregorianCalendar::__construct', 0, 'year');
        $month = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'IntlGregorianCalendar::__construct', 1, 'month');
        $day = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[3], 'IntlGregorianCalendar::__construct', 2, 'day');
        VmIntlCalendar::assertInt32Field($year, 1, 'IntlGregorianCalendar::__construct');
        VmIntlCalendar::assertInt32Field($month, 2, 'IntlGregorianCalendar::__construct');
        VmIntlCalendar::assertInt32Field($day, 3, 'IntlGregorianCalendar::__construct');
        $hour = null;
        $minute = null;
        $second = null;
        if ($userArgc >= 5) {
            $hour = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[4], 'IntlGregorianCalendar::__construct', 3, 'hour');
            $minute = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[5], 'IntlGregorianCalendar::__construct', 4, 'minute');
            VmIntlCalendar::assertInt32Field($hour, 4, 'IntlGregorianCalendar::__construct');
            VmIntlCalendar::assertInt32Field($minute, 5, 'IntlGregorianCalendar::__construct');
        }
        if (6 === $userArgc) {
            $second = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[6], 'IntlGregorianCalendar::__construct', 5, 'second');
            VmIntlCalendar::assertInt32Field($second, 6, 'IntlGregorianCalendar::__construct');
        }
        VmIntlCalendar::createGregorianFromDate($ctx, $year, $month, $day, $hour, $minute, $second, $obj);
    }
}

/** IntlGregorianCalendar::createFromDate() — php-src (#20906). */
final class IntlGregorianCalendarCreateFromDate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromDate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlGregorianCalendar::createFromDate() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $year = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[0], 'IntlGregorianCalendar::createFromDate', 0, 'year');
        $month = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlGregorianCalendar::createFromDate', 1, 'month');
        $day = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'IntlGregorianCalendar::createFromDate', 2, 'dayOfMonth');
        VmIntlCalendar::assertInt32Field($year, 1, 'IntlGregorianCalendar::createFromDate');
        VmIntlCalendar::assertInt32Field($month, 2, 'IntlGregorianCalendar::createFromDate');
        VmIntlCalendar::assertInt32Field($day, 3, 'IntlGregorianCalendar::createFromDate');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmIntlCalendar::createGregorianFromDate(
            $frame->vmContext,
            $year,
            $month,
            $day
        ));
    }
}

/** IntlGregorianCalendar::createFromDateTime() — php-src (#20906). */
final class IntlGregorianCalendarCreateFromDateTime extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromDateTime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'IntlGregorianCalendar::createFromDateTime() expects between 5 and 6 arguments, %d given',
                $argc
            ));
        }
        $year = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[0], 'IntlGregorianCalendar::createFromDateTime', 0, 'year');
        $month = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlGregorianCalendar::createFromDateTime', 1, 'month');
        $day = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'IntlGregorianCalendar::createFromDateTime', 2, 'dayOfMonth');
        $hour = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[3], 'IntlGregorianCalendar::createFromDateTime', 3, 'hour');
        $minute = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[4], 'IntlGregorianCalendar::createFromDateTime', 4, 'minute');
        VmIntlCalendar::assertInt32Field($year, 1, 'IntlGregorianCalendar::createFromDateTime');
        VmIntlCalendar::assertInt32Field($month, 2, 'IntlGregorianCalendar::createFromDateTime');
        VmIntlCalendar::assertInt32Field($day, 3, 'IntlGregorianCalendar::createFromDateTime');
        VmIntlCalendar::assertInt32Field($hour, 4, 'IntlGregorianCalendar::createFromDateTime');
        VmIntlCalendar::assertInt32Field($minute, 5, 'IntlGregorianCalendar::createFromDateTime');
        $second = null;
        if (6 === $argc) {
            $secVar = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_NULL !== $secVar->type) {
                $second = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[5], 'IntlGregorianCalendar::createFromDateTime', 5, 'second');
                VmIntlCalendar::assertInt32Field($second, 6, 'IntlGregorianCalendar::createFromDateTime');
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmIntlCalendar::createGregorianFromDate(
            $frame->vmContext,
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second
        ));
    }
}

/** IntlGregorianCalendar::isLeapYear() — php-src intlgregcal_is_leap_year (#20906). */
final class IntlGregorianCalendarIsLeapYear extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isLeapYear');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlGregorianCalendar::isLeapYear() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isGregorianCalendarObject($receiver->toObject())) {
            throw new \Error('IntlGregorianCalendar::isLeapYear() called on incompatible object');
        }
        $year = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlGregorianCalendar::isLeapYear', 1, 'year');
        VmIntlCalendar::assertInt32Field($year, 1, 'IntlGregorianCalendar::isLeapYear');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlCalendar::isLeapYear($receiver->toObject(), $year));
    }
}

/** IntlGregorianCalendar::getGregorianChange() — php-src intlgregcal_get_gregorian_change (#20906). */
final class IntlGregorianCalendarGetGregorianChange extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getGregorianChange');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlGregorianCalendar::getGregorianChange() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isGregorianCalendarObject($receiver->toObject())) {
            throw new \Error('IntlGregorianCalendar::getGregorianChange() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmIntlCalendar::getGregorianChange($receiver->toObject()));
    }
}

/** IntlGregorianCalendar::setGregorianChange() — php-src intlgregcal_set_gregorian_change (#20906). */
final class IntlGregorianCalendarSetGregorianChange extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setGregorianChange');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlGregorianCalendar::setGregorianChange() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isGregorianCalendarObject($receiver->toObject())) {
            throw new \Error('IntlGregorianCalendar::setGregorianChange() called on incompatible object');
        }
        $ts = VmIntlCalendar::coerceFloatArg($frame->calledArgs[1], 'IntlGregorianCalendar::setGregorianChange', 1, 'timestamp');
        $ok = VmIntlCalendar::setGregorianChange($receiver->toObject(), $ts);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}
