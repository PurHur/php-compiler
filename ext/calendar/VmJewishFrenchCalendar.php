<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * Jewish and French republican calendar conversions (php-src ext/calendar/jewish.c, french.c; #11875).
 */
final class VmJewishFrenchCalendar
{
    private const FRENCH_SDN_OFFSET = 2375474;
    private const FRENCH_DAYS_PER_4_YEARS = 1461;
    private const FRENCH_DAYS_PER_MONTH = 30;
    private const FRENCH_FIRST_VALID = 2375840;
    private const FRENCH_LAST_VALID = 2380952;

    private const HALAKIM_PER_HOUR = 1080;
    private const HALAKIM_PER_DAY = 25920;
    private const HALAKIM_PER_LUNAR_CYCLE = 765433;
    private const HALAKIM_PER_METONIC_CYCLE = 179876755;
    private const JEWISH_SDN_OFFSET = 347997;
    private const JEWISH_SDN_MAX = 324542846;
    private const NEW_MOON_OF_CREATION = 31524;
    private const NOON = 19440;
    private const AM3_11_20 = 9924;
    private const AM9_32_43 = 16789;

    /** @var list<int> */
    private const MONTHS_PER_YEAR = [
        12, 12, 13, 12, 12, 13, 12, 13, 12, 12, 13, 12, 12, 13, 12, 12, 13, 12, 13,
    ];

    /** @var list<int> */
    private const YEAR_OFFSET = [
        0, 12, 24, 37, 49, 61, 74, 86, 99, 111, 123,
        136, 148, 160, 173, 185, 197, 210, 222,
    ];

    public static function juliantojd(int $month, int $day, int $year): int
    {
        return VmCalendar::julianToJd($month, $day, $year);
    }

    public static function frenchtojd(int $month, int $day, int $year): int
    {
        if ($year < 1 || $year > 14 || $month < 1 || $month > 13 || $day < 1 || $day > 30) {
            return 0;
        }

        return intdiv($year * self::FRENCH_DAYS_PER_4_YEARS, 4)
            + ($month - 1) * self::FRENCH_DAYS_PER_MONTH
            + $day
            + self::FRENCH_SDN_OFFSET;
    }

    public static function jdtofrench(int $julday): string
    {
        if ($julday < self::FRENCH_FIRST_VALID || $julday > self::FRENCH_LAST_VALID) {
            return '0/0/0';
        }
        $temp = ($julday - self::FRENCH_SDN_OFFSET) * 4 - 1;
        $year = intdiv($temp, self::FRENCH_DAYS_PER_4_YEARS);
        $dayOfYear = intdiv($temp % self::FRENCH_DAYS_PER_4_YEARS, 4);
        $month = intdiv($dayOfYear, self::FRENCH_DAYS_PER_MONTH) + 1;
        $day = $dayOfYear % self::FRENCH_DAYS_PER_MONTH + 1;

        return $month.'/'.$day.'/'.$year;
    }

    public static function jewishtojd(int $month, int $day, int $year): int
    {
        $sdn = self::jewishToSdn($year, $month, $day);

        return 0 === $sdn ? 0 : $sdn + self::JEWISH_SDN_OFFSET;
    }

    public static function jdtojewish(int $julday, int $mode = 0): string
    {
        if ($julday <= self::JEWISH_SDN_OFFSET || $julday > self::JEWISH_SDN_MAX) {
            return '0/0/0';
        }
        [$year, $month, $day] = self::sdnToJewish($julday - self::JEWISH_SDN_OFFSET);
        if (0 === $year) {
            return '0/0/0';
        }

        return $month.'/'.$day.'/'.$year;
    }

    public static function frenchToSdn(int $year, int $month, int $day): int
    {
        return self::frenchtojd($month, $day, $year);
    }

    public static function jewishToSdnPublic(int $year, int $month, int $day): int
    {
        $sdn = self::jewishToSdn($year, $month, $day);

        return 0 === $sdn ? 0 : $sdn + self::JEWISH_SDN_OFFSET;
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function sdnToFrench(int $sdn): array
    {
        if ($sdn < self::FRENCH_FIRST_VALID || $sdn > self::FRENCH_LAST_VALID) {
            return [0, 0, 0];
        }
        $temp = ($sdn - self::FRENCH_SDN_OFFSET) * 4 - 1;
        $year = intdiv($temp, self::FRENCH_DAYS_PER_4_YEARS);
        $dayOfYear = intdiv($temp % self::FRENCH_DAYS_PER_4_YEARS, 4);
        $month = intdiv($dayOfYear, self::FRENCH_DAYS_PER_MONTH) + 1;
        $day = $dayOfYear % self::FRENCH_DAYS_PER_MONTH + 1;

        return [$year, $month, $day];
    }

    public static function sdnToJewishFromJd(int $julday): array
    {
        if ($julday <= self::JEWISH_SDN_OFFSET || $julday > self::JEWISH_SDN_MAX) {
            return [0, 0, 0];
        }

        return self::sdnToJewish($julday - self::JEWISH_SDN_OFFSET);
    }

    public static function jewishOffset(): int
    {
        return self::JEWISH_SDN_OFFSET;
    }

    private static function jewishToSdn(int $year, int $month, int $day): int
    {
        if ($year <= 0 || $year >= \PHP_INT_MAX - 1 || $day <= 0 || $day > 30) {
            return 0;
        }

        $sdn = 0;
        switch ($month) {
            case 1:
            case 2:
                $tishri1 = self::findStartOfYear($year);
                $sdn = 1 === $month ? $tishri1 + $day - 1 : $tishri1 + $day + 29;
                break;
            case 3:
                $tishri1 = self::findStartOfYear($year);
                $tishri1After = self::tishri1AfterYear($year);
                $yearLength = $tishri1After - $tishri1;
                $sdn = ($yearLength === 355 || $yearLength === 385) ? $tishri1 + $day + 59 : $tishri1 + $day + 58;
                break;
            case 4:
            case 5:
            case 6:
                $tishri1After = self::findStartOfYear($year + 1);
                $lengthOfAdar = 12 === self::MONTHS_PER_YEAR[($year - 1) % 19] ? 29 : 59;
                $sdn = match ($month) {
                    4 => $tishri1After + $day - $lengthOfAdar - 237,
                    5 => $tishri1After + $day - $lengthOfAdar - 208,
                    default => $tishri1After + $day - $lengthOfAdar - 178,
                };
                break;
            default:
                $tishri1After = self::findStartOfYear($year + 1);
                $sdn = match ($month) {
                    7 => $tishri1After + $day - 207,
                    8 => $tishri1After + $day - 178,
                    9 => $tishri1After + $day - 148,
                    10 => $tishri1After + $day - 119,
                    11 => $tishri1After + $day - 89,
                    12 => $tishri1After + $day - 60,
                    13 => $tishri1After + $day - 30,
                    default => 0,
                };
                if (0 === $sdn) {
                    return 0;
                }
                break;
        }

        return $sdn;
    }

    /** @return array{0: int, 1: int, 2: int} year, month, day */
    private static function sdnToJewish(int $inputDay): array
    {
        [$metonicCycle, $metonicYear, $day, $halakim] = self::findTishriMolad($inputDay);
        $tishri1 = self::tishri1($metonicYear, $day, $halakim);

        if ($inputDay >= $tishri1) {
            $year = $metonicCycle * 19 + $metonicYear + 1;
            if ($inputDay < $tishri1 + 59) {
                if ($inputDay < $tishri1 + 30) {
                    return [$year, 1, $inputDay - $tishri1 + 1];
                }

                return [$year, 2, $inputDay - $tishri1 - 29];
            }
            $tishri1After = self::tishri1AfterMetonic($metonicCycle, $metonicYear, $day, $halakim);
        } else {
            $year = $metonicCycle * 19 + $metonicYear;
            if ($inputDay >= $tishri1 - 177) {
                if ($inputDay > $tishri1 - 30) {
                    return [$year, 13, $inputDay - $tishri1 + 30];
                }
                if ($inputDay > $tishri1 - 60) {
                    return [$year, 12, $inputDay - $tishri1 + 60];
                }
                if ($inputDay > $tishri1 - 89) {
                    return [$year, 11, $inputDay - $tishri1 + 89];
                }
                if ($inputDay > $tishri1 - 119) {
                    return [$year, 10, $inputDay - $tishri1 + 119];
                }
                if ($inputDay > $tishri1 - 148) {
                    return [$year, 9, $inputDay - $tishri1 + 148];
                }

                return [$year, 8, $inputDay - $tishri1 + 178];
            }
            if (13 === self::MONTHS_PER_YEAR[($year - 1) % 19]) {
                $month = 7;
                $dayNum = $inputDay - $tishri1 + 207;
                if ($dayNum > 0) {
                    return [$year, $month, $dayNum];
                }
                --$month;
                $dayNum += 30;
                if ($dayNum > 0) {
                    return [$year, $month, $dayNum];
                }
                --$month;
                $dayNum += 30;
                if ($dayNum > 0) {
                    return [$year, $month, $dayNum];
                }
                --$month;
                $dayNum += 29;
            } else {
                $month = 7;
                $dayNum = $inputDay - $tishri1 + 207;
                if ($dayNum > 0) {
                    return [$year, $month, $dayNum];
                }
                $month -= 2;
                $dayNum += 30;
                if ($dayNum > 0) {
                    return [$year, $month, $dayNum];
                }
                --$month;
                $dayNum += 29;
            }
            if ($dayNum > 0) {
                return [$year, $month, $dayNum];
            }
            $tishri1After = $tishri1;
            [$metonicCycle, $metonicYear, $day, $halakim] = self::findTishriMolad($day - 365);
            $tishri1 = self::tishri1($metonicYear, $day, $halakim);
        }

        $yearLength = $tishri1After - $tishri1;
        $dayNum = $inputDay - $tishri1 - 29;
        if (355 === $yearLength || 385 === $yearLength) {
            if ($dayNum <= 30) {
                return [$year, 2, $dayNum];
            }
            $dayNum -= 30;
        } else {
            if ($dayNum <= 29) {
                return [$year, 2, $dayNum];
            }
            $dayNum -= 29;
        }

        return [$year, 3, $dayNum];
    }

    private static function findStartOfYear(int $year): int
    {
        $metonicCycle = intdiv($year - 1, 19);
        $metonicYear = ($year - 1) % 19;
        [$moladDay, $moladHalakim] = self::moladOfMetonicCycle($metonicCycle);
        $moladHalakim += self::HALAKIM_PER_LUNAR_CYCLE * self::YEAR_OFFSET[$metonicYear];
        $moladDay += intdiv($moladHalakim, self::HALAKIM_PER_DAY);
        $moladHalakim %= self::HALAKIM_PER_DAY;

        return self::tishri1($metonicYear, $moladDay, $moladHalakim);
    }

    private static function tishri1AfterYear(int $year): int
    {
        $metonicCycle = intdiv($year - 1, 19);
        $metonicYear = ($year - 1) % 19;
        [$moladDay, $moladHalakim] = self::moladOfMetonicCycle($metonicCycle);
        $moladHalakim += self::HALAKIM_PER_LUNAR_CYCLE * self::MONTHS_PER_YEAR[$metonicYear];
        $moladDay += intdiv($moladHalakim, self::HALAKIM_PER_DAY);
        $moladHalakim %= self::HALAKIM_PER_DAY;

        return self::tishri1(($metonicYear + 1) % 19, $moladDay, $moladHalakim);
    }

    private static function tishri1AfterMetonic(int $metonicCycle, int $metonicYear, int $day, int $halakim): int
    {
        $halakim += self::HALAKIM_PER_LUNAR_CYCLE * self::MONTHS_PER_YEAR[$metonicYear];
        $day += intdiv($halakim, self::HALAKIM_PER_DAY);
        $halakim %= self::HALAKIM_PER_DAY;

        return self::tishri1(($metonicYear + 1) % 19, $day, $halakim);
    }

    /** @return array{0: int, 1: int} */
    private static function moladOfMetonicCycle(int $metonicCycle): array
    {
        if ($metonicCycle > intdiv(\PHP_INT_MAX - self::NEW_MOON_OF_CREATION, self::HALAKIM_PER_METONIC_CYCLE)) {
            return [0, 0];
        }

        $r1 = self::NEW_MOON_OF_CREATION + $metonicCycle * (self::HALAKIM_PER_METONIC_CYCLE & 0xFFFF);
        $r2 = ($r1 >> 16) + $metonicCycle * ((self::HALAKIM_PER_METONIC_CYCLE >> 16) & 0xFFFF);
        $d2 = intdiv($r2, self::HALAKIM_PER_DAY);
        $r2 -= $d2 * self::HALAKIM_PER_DAY;
        $r1 = (($r2 << 16) | ($r1 & 0xFFFF));
        $d1 = intdiv($r1, self::HALAKIM_PER_DAY);
        $r1 -= $d1 * self::HALAKIM_PER_DAY;

        return [($d2 << 16) | $d1, $r1];
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} */
    private static function findTishriMolad(int $inputDay): array
    {
        $metonicCycle = intdiv($inputDay + 310, 6940);
        [$moladDay, $moladHalakim] = self::moladOfMetonicCycle($metonicCycle);
        while ($moladDay < $inputDay - 6940 + 310) {
            ++$metonicCycle;
            $moladHalakim += self::HALAKIM_PER_METONIC_CYCLE;
            $moladDay += intdiv($moladHalakim, self::HALAKIM_PER_DAY);
            $moladHalakim %= self::HALAKIM_PER_DAY;
        }

        $metonicYear = 0;
        for (; $metonicYear < 18; ++$metonicYear) {
            if ($moladDay > $inputDay - 74) {
                break;
            }
            $moladHalakim += self::HALAKIM_PER_LUNAR_CYCLE * self::MONTHS_PER_YEAR[$metonicYear];
            $moladDay += intdiv($moladHalakim, self::HALAKIM_PER_DAY);
            $moladHalakim %= self::HALAKIM_PER_DAY;
        }

        return [$metonicCycle, $metonicYear, $moladDay, $moladHalakim];
    }

    private static function tishri1(int $metonicYear, int $moladDay, int $moladHalakim): int
    {
        $tishri1 = $moladDay;
        $dow = $tishri1 % 7;
        $leapYear = \in_array($metonicYear, [2, 5, 7, 10, 13, 16, 18], true);
        $lastWasLeapYear = \in_array($metonicYear, [3, 6, 8, 11, 14, 17, 0], true);

        if ($moladHalakim >= self::NOON
            || (!$leapYear && 2 === $dow && $moladHalakim >= self::AM3_11_20)
            || ($lastWasLeapYear && 1 === $dow && $moladHalakim >= self::AM9_32_43)) {
            ++$tishri1;
            ++$dow;
            if (7 === $dow) {
                $dow = 0;
            }
        }
        if (\in_array($dow, [0, 3, 5], true)) {
            ++$tishri1;
        }

        return $tishri1;
    }
}
