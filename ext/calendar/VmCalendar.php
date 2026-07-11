<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Pure PHP calendar math ported from php-src ext/calendar (sdncal / easter.c; #7223).
 *
 * php-src: ext/calendar/gregor.c, julian.c, calendar.c, cal_unix.c, easter.c
 */
final class VmCalendar
{
    private const GREGOR_SDN_OFFSET = 32045;
    private const JULIAN_SDN_OFFSET = 32083;
    private const DAYS_PER_5_MONTHS = 153;
    private const DAYS_PER_4_YEARS = 1461;
    private const DAYS_PER_400_YEARS = 146097;
    private const UNIX_EPOCH_JD = 2440588;
    private const SECS_PER_DAY = 86400;

    public static function calDaysInMonth(int $calendar, int $month, int $year): int
    {
        $sdnStart = self::calendarToSdn($calendar, $year, $month, 1);
        if (0 === $sdnStart) {
            throw new \ValueError('Invalid date');
        }

        $nextMonth = 1 + $month;
        $sdnNext = self::calendarToSdn($calendar, $year, $nextMonth, 1);
        if (0 === $sdnNext) {
            if (-1 === $year) {
                $sdnNext = self::calendarToSdn($calendar, 1, 1, 1);
            } else {
                $sdnNext = self::calendarToSdn($calendar, $year + 1, 1, 1);
                if (CalendarConstants::CAL_FRENCH === $calendar && 0 === $sdnNext) {
                    $sdnNext = 2380953;
                }
            }
        }
        if (0 === $sdnNext) {
            throw new \ValueError('Invalid date');
        }

        return (int) ($sdnNext - $sdnStart);
    }

    public static function gregorianToJd(int $month, int $day, int $year): int
    {
        return self::gregorianToSdn($year, $month, $day);
    }

    public static function julianToJd(int $month, int $day, int $year): int
    {
        return self::julianToSdn($year, $month, $day);
    }

    public static function jdtogregorian(int $julday): string
    {
        [$year, $month, $day] = self::sdnToGregorian($julday);

        return $month.'/'.$day.'/'.$year;
    }

    public static function jdtojulian(int $julday): string
    {
        [$year, $month, $day] = self::sdnToJulian($julday);

        return $month.'/'.$day.'/'.$year;
    }

    public static function calToJd(int $calendar, int $month, int $day, int $year): int
    {
        return match ($calendar) {
            CalendarConstants::CAL_GREGORIAN => self::gregorianToSdn($year, $month, $day),
            CalendarConstants::CAL_JULIAN => self::julianToSdn($year, $month, $day),
            CalendarConstants::CAL_JEWISH => VmJewishFrenchCalendar::jewishToSdnPublic($year, $month, $day),
            CalendarConstants::CAL_FRENCH => VmJewishFrenchCalendar::frenchToSdn($year, $month, $day),
            default => throw new \LogicException(
                'Calendar ID '.$calendar.' is not implemented in this compiler build (issue #3742)'
            ),
        };
    }

    public static function unixtojd(?int $timestamp = null): int
    {
        if (null === $timestamp) {
            $timestamp = VmDate::time();
        } elseif ($timestamp < 0) {
            throw new \ValueError(
                'unixtojd(): Argument #1 ($timestamp) must be greater than or equal to 0'
            );
        }
        $parts = VmDate::getdate($timestamp);

        return self::gregorianToSdn(
            self::hashGetInt($parts, 'year'),
            self::hashGetInt($parts, 'mon'),
            self::hashGetInt($parts, 'mday')
        );
    }

    public static function jdtounix(int $jday): int
    {
        $maxJd = self::UNIX_EPOCH_JD + intdiv(\PHP_INT_MAX, self::SECS_PER_DAY);
        if ($jday < self::UNIX_EPOCH_JD || $jday > $maxJd) {
            throw new \ValueError(
                \sprintf('jdtounix(): Argument #1 ($jday) must be between %d and %d', self::UNIX_EPOCH_JD, $maxJd)
            );
        }

        return ($jday - self::UNIX_EPOCH_JD) * self::SECS_PER_DAY;
    }

    public static function easterDate(int $year): int
    {
        $easter = self::easterDays($year, CalendarConstants::CAL_EASTER_DEFAULT);
        if ($easter < 11) {
            $month = 3;
            $day = $easter + 21;
        } else {
            $month = 4;
            $day = $easter - 10;
        }

        return self::localMidnightTimestamp($year, $month, $day);
    }

    public static function dayOfWeek(int $sdn): int
    {
        return (int) (($sdn % 7 + 8) % 7);
    }

    public static function calInfo(int $cal): HashTable
    {
        $meta = CalendarTables::calendarMeta($cal);
        $ht = new HashTable();
        self::hashSetString($ht, 'calname', $meta['name']);
        self::hashSetString($ht, 'calsymbol', $meta['symbol']);
        self::hashSetLong($ht, 'maxdaysinmonth', $meta['maxDaysInMonth']);
        self::hashSetArray($ht, 'months', self::indexedStringArray(
            $meta['monthLong'],
            1,
            $meta['numMonths']
        ));
        self::hashSetArray($ht, 'abbrevmonths', self::indexedStringArray(
            $meta['monthShort'],
            1,
            $meta['numMonths']
        ));

        return $ht;
    }

    /** @return HashTable */
    public static function calInfoAll(): HashTable
    {
        $all = new HashTable();
        for ($i = 0; $i < CalendarConstants::CAL_NUM_CALS; ++$i) {
            $var = new Variable(Variable::TYPE_ARRAY);
            $var->array(self::calInfo($i));
            $all->add((string) $i, $var);
        }

        return $all;
    }

    public static function calFromJd(int $jd, int $cal): HashTable
    {
        [$year, $month, $day] = match ($cal) {
            CalendarConstants::CAL_GREGORIAN => self::sdnToGregorian($jd),
            CalendarConstants::CAL_JULIAN => self::sdnToJulian($jd),
            CalendarConstants::CAL_JEWISH => self::sdnToJewishParts($jd),
            CalendarConstants::CAL_FRENCH => VmJewishFrenchCalendar::sdnToFrench($jd),
            default => throw new \LogicException(
                'Calendar ID '.$cal.' is not implemented in this compiler build (issue #3742)'
            ),
        };

        $ht = new HashTable();
        self::hashSetString($ht, 'date', $month.'/'.$day.'/'.$year);
        self::hashSetLong($ht, 'month', $month);
        self::hashSetLong($ht, 'day', $day);
        self::hashSetLong($ht, 'year', $year);

        if (CalendarConstants::CAL_JEWISH !== $cal || $year > 0) {
            $dow = self::dayOfWeek($jd);
            self::hashSetLong($ht, 'dow', $dow);
            self::hashSetString($ht, 'abbrevdayname', CalendarTables::DAY_SHORT[$dow]);
            self::hashSetString($ht, 'dayname', CalendarTables::DAY_LONG[$dow]);
        } else {
            self::hashSetNull($ht, 'dow');
            self::hashSetString($ht, 'abbrevdayname', '');
            self::hashSetString($ht, 'dayname', '');
        }

        $meta = CalendarTables::calendarMeta($cal);
        if (CalendarConstants::CAL_JEWISH === $cal) {
            $abbrev = $year > 0 ? $meta['monthShort'][$month] : '';
            $long = $year > 0 ? $meta['monthLong'][$month] : '';
        } else {
            $abbrev = $meta['monthShort'][$month];
            $long = $meta['monthLong'][$month];
        }
        self::hashSetString($ht, 'abbrevmonth', $abbrev);
        self::hashSetString($ht, 'monthname', $long);

        return $ht;
    }

    public static function jdMonthName(int $jd, int $mode): string
    {
        return match ($mode) {
            CalendarConstants::CAL_MONTH_GREGORIAN_LONG => self::monthNameFromSdn(
                self::sdnToGregorian($jd),
                CalendarTables::GREGOR_MONTH_LONG
            ),
            CalendarConstants::CAL_MONTH_JULIAN_SHORT => self::monthNameFromSdn(
                self::sdnToJulian($jd),
                CalendarTables::GREGOR_MONTH_SHORT
            ),
            CalendarConstants::CAL_MONTH_JULIAN_LONG => self::monthNameFromSdn(
                self::sdnToJulian($jd),
                CalendarTables::GREGOR_MONTH_LONG
            ),
            CalendarConstants::CAL_MONTH_JEWISH => self::monthNameFromJewishSdn($jd),
            CalendarConstants::CAL_MONTH_FRENCH => self::monthNameFromFrenchSdn($jd),
            default => self::monthNameFromSdn(
                self::sdnToGregorian($jd),
                CalendarTables::GREGOR_MONTH_SHORT
            ),
        };
    }

    /**
     * @return int|string
     */
    public static function jdDayOfWeek(int $jd, int $mode)
    {
        $day = self::dayOfWeek($jd);

        return match ($mode) {
            CalendarConstants::CAL_DOW_LONG => CalendarTables::DAY_LONG[$day],
            CalendarConstants::CAL_DOW_SHORT => CalendarTables::DAY_SHORT[$day],
            default => $day,
        };
    }

    public static function easterDays(int $year, int $method = CalendarConstants::CAL_EASTER_DEFAULT): int
    {
        $golden = ($year % 19) + 1;

        if (($year <= 1582 && CalendarConstants::CAL_EASTER_ALWAYS_GREGORIAN !== $method)
            || ($year >= 1583 && $year <= 1752
                && CalendarConstants::CAL_EASTER_ROMAN !== $method
                && CalendarConstants::CAL_EASTER_ALWAYS_GREGORIAN !== $method)
            || CalendarConstants::CAL_EASTER_ALWAYS_JULIAN === $method) {
            $dom = ($year + intdiv($year, 4) + 5) % 7;
            if ($dom < 0) {
                $dom += 7;
            }
            $pfm = (3 - (11 * $golden) - 7) % 30;
            if ($pfm < 0) {
                $pfm += 30;
            }
        } else {
            $dom = ($year + intdiv($year, 4) - intdiv($year, 100) + intdiv($year, 400)) % 7;
            if ($dom < 0) {
                $dom += 7;
            }
            $solar = intdiv($year - 1600, 100) - intdiv($year - 1600, 400);
            $lunar = intdiv(intdiv($year - 1400, 100) * 8, 25);
            $pfm = (3 - (11 * $golden) + $solar - $lunar) % 30;
            if ($pfm < 0) {
                $pfm += 30;
            }
        }

        if (29 === $pfm || (28 === $pfm && $golden > 11)) {
            --$pfm;
        }

        $tmp = (4 - $pfm - $dom) % 7;
        if ($tmp < 0) {
            $tmp += 7;
        }

        return (int) ($pfm + $tmp + 1);
    }

    private static function calendarToSdn(int $calendar, int $year, int $month, int $day): int
    {
        return match ($calendar) {
            CalendarConstants::CAL_GREGORIAN => self::gregorianToSdn($year, $month, $day),
            CalendarConstants::CAL_JULIAN => self::julianToSdn($year, $month, $day),
            CalendarConstants::CAL_JEWISH => VmJewishFrenchCalendar::jewishToSdnPublic($year, $month, $day),
            CalendarConstants::CAL_FRENCH => VmJewishFrenchCalendar::frenchToSdn($year, $month, $day),
            default => throw new \LogicException(
                'Calendar ID '.$calendar.' is not implemented in this compiler build (issue #3742)'
            ),
        };
    }

    /** @return array{0: int, 1: int, 2: int} year, month, day */
    private static function sdnToGregorian(int $sdn): array
    {
        if ($sdn <= 0 || $sdn > intdiv(\PHP_INT_MAX - 4 * self::GREGOR_SDN_OFFSET, 4)) {
            return [0, 0, 0];
        }
        $temp = ($sdn + self::GREGOR_SDN_OFFSET) * 4 - 1;
        $century = intdiv($temp, self::DAYS_PER_400_YEARS);
        $temp = intdiv($temp % self::DAYS_PER_400_YEARS, 4) * 4 + 3;
        $year = $century * 100 + intdiv($temp, self::DAYS_PER_4_YEARS);
        $dayOfYear = intdiv($temp % self::DAYS_PER_4_YEARS, 4) + 1;
        $temp = $dayOfYear * 5 - 3;
        $month = intdiv($temp, self::DAYS_PER_5_MONTHS);
        $day = intdiv($temp % self::DAYS_PER_5_MONTHS, 5) + 1;
        if ($month < 10) {
            $month += 3;
        } else {
            ++$year;
            $month -= 9;
        }
        $year -= 4800;
        if ($year <= 0) {
            --$year;
        }

        return [$year, $month, $day];
    }

    /** @return array{0: int, 1: int, 2: int} year, month, day */
    private static function sdnToJulian(int $sdn): array
    {
        if ($sdn <= 0) {
            return [0, 0, 0];
        }
        $temp = $sdn * 4 + (self::JULIAN_SDN_OFFSET * 4 - 1);
        $year = intdiv($temp, self::DAYS_PER_4_YEARS);
        $dayOfYear = intdiv($temp % self::DAYS_PER_4_YEARS, 4) + 1;
        $temp = $dayOfYear * 5 - 3;
        $month = intdiv($temp, self::DAYS_PER_5_MONTHS);
        $day = intdiv($temp % self::DAYS_PER_5_MONTHS, 5) + 1;
        if ($month < 10) {
            $month += 3;
        } else {
            ++$year;
            $month -= 9;
        }
        $year -= 4800;
        if ($year <= 0) {
            --$year;
        }

        return [$year, $month, $day];
    }

    /** @param list<string> $names */
    private static function monthNameFromSdn(array $parts, array $names): string
    {
        [, $month] = $parts;

        return $names[$month] ?? '';
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function sdnToJewishParts(int $jd): array
    {
        return VmJewishFrenchCalendar::sdnToJewishFromJd($jd);
    }

    private static function monthNameFromJewishSdn(int $jd): string
    {
        [, $month] = self::sdnToJewishParts($jd);
        $names = CalendarTables::JEWISH_MONTH_LEAP;

        return $names[$month] ?? '';
    }

    private static function monthNameFromFrenchSdn(int $jd): string
    {
        [, $month] = VmJewishFrenchCalendar::sdnToFrench($jd);

        return CalendarTables::FRENCH_MONTH[$month] ?? '';
    }

    /** @param list<string> $values */
    private static function indexedStringArray(array $values, int $start, int $count): HashTable
    {
        $ht = new HashTable();
        for ($i = 0; $i < $count; ++$i) {
            $idx = $start + $i;
            self::hashSetString($ht, (string) $idx, $values[$idx]);
        }

        return $ht;
    }

    private static function hashSetString(HashTable $ht, string $key, string $value): void
    {
        $var = new Variable();
        $var->string($value);
        $ht->add($key, $var);
    }

    private static function hashSetLong(HashTable $ht, string $key, int $value): void
    {
        $var = new Variable(Variable::TYPE_INTEGER);
        $var->int($value);
        $ht->add($key, $var);
    }

    private static function hashSetNull(HashTable $ht, string $key): void
    {
        $var = new Variable(Variable::TYPE_NULL);
        $var->null();
        $ht->add($key, $var);
    }

    private static function hashSetArray(HashTable $ht, string $key, HashTable $value): void
    {
        $var = new Variable(Variable::TYPE_ARRAY);
        $var->array($value);
        $ht->add($key, $var);
    }

    private static function hashGetInt(HashTable $ht, string $key): int
    {
        $keyVar = new Variable();
        $keyVar->string($key);
        $val = $ht->findVariable($keyVar, false);
        if (null === $val) {
            throw new \LogicException('calendar date breakdown missing key '.$key);
        }

        return $val->resolveIndirect()->toInt();
    }

    private static function gregorianToSdn(int $inputYear, int $inputMonth, int $inputDay): int
    {
        if (0 === $inputYear || $inputYear < -4714
            || $inputMonth <= 0 || $inputMonth > 12
            || $inputDay <= 0 || $inputDay > 31) {
            return 0;
        }
        if (-4714 === $inputYear) {
            if ($inputMonth < 11 || (11 === $inputMonth && $inputDay < 25)) {
                return 0;
            }
        }

        if ($inputYear < 0) {
            $year = $inputYear + 4801;
        } else {
            $year = $inputYear + 4800;
        }

        if ($inputMonth > 2) {
            $month = $inputMonth - 3;
        } else {
            $month = $inputMonth + 9;
            --$year;
        }

        return intdiv(intdiv($year, 100) * self::DAYS_PER_400_YEARS, 4)
            + intdiv(($year % 100) * self::DAYS_PER_4_YEARS, 4)
            + intdiv($month * self::DAYS_PER_5_MONTHS + 2, 5)
            + $inputDay
            - self::GREGOR_SDN_OFFSET;
    }

    private static function julianToSdn(int $inputYear, int $inputMonth, int $inputDay): int
    {
        if (0 === $inputYear || $inputYear < -4713
            || $inputMonth <= 0 || $inputMonth > 12
            || $inputDay <= 0 || $inputDay > 31) {
            return 0;
        }
        if (-4713 === $inputYear && 1 === $inputMonth && 1 === $inputDay) {
            return 0;
        }

        if ($inputYear < 0) {
            $year = $inputYear + 4801;
        } else {
            $year = $inputYear + 4800;
        }

        if ($inputMonth > 2) {
            $month = $inputMonth - 3;
        } else {
            $month = $inputMonth + 9;
            --$year;
        }

        return intdiv($year * self::DAYS_PER_4_YEARS, 4)
            + intdiv($month * self::DAYS_PER_5_MONTHS + 2, 5)
            + $inputDay
            - self::JULIAN_SDN_OFFSET;
    }

    private static function localMidnightTimestamp(int $year, int $month, int $day): int
    {
        $ts = VmDate::mktime(0, 0, 0, $month, $day, $year);
        if (false !== $ts) {
            return $ts;
        }

        throw new \LogicException('easter_date() requires mktime support in this compiler build');
    }
}
