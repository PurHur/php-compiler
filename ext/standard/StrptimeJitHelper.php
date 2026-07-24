<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * strptime() for VM + compiled JIT/AOT modules (#9132, #22771, php-in-PHP).
 *
 * Pure-PHP parser (no host \strptime — recurses under nested AOT compile).
 * HashTable population via Variable works on VM/JIT; standalone AOT NestedJitCompileScope
 * still cannot mutate HashTable with Variable (#16075 class) — calendar AOT helpers share that gap.
 *
 * SSOT: {@see strptimeArgv()} also via {@see VmDate::strptime()}.
 * php-src: ext/date/php_date.c — PHP_FUNCTION(strptime)
 */
final class StrptimeJitHelper
{
    public static function strptimeArgv(string $date, string $format): HashTable|false
    {
        $di = 0;
        $fi = 0;
        $dLen = \strlen($date);
        $fLen = \strlen($format);
        $tmSec = 0;
        $tmMin = 0;
        $tmHour = 0;
        $tmMday = 0;
        $tmMon = 0;
        $tmYear = 0;
        $tmWday = 0;
        $tmYday = 0;
        $haveYear = 0;
        $haveMon = 0;
        $haveMday = 0;

        while ($fi < $fLen) {
            $fc = $format[$fi];
            if ('%' !== $fc) {
                if (' ' === $fc || "\t" === $fc || "\n" === $fc || "\r" === $fc || "\v" === $fc || "\f" === $fc) {
                    while ($di < $dLen) {
                        $ch = $date[$di];
                        if (' ' !== $ch && "\t" !== $ch && "\n" !== $ch && "\r" !== $ch && "\v" !== $ch && "\f" !== $ch) {
                            break;
                        }
                        ++$di;
                    }
                    ++$fi;
                    continue;
                }
                if ($di >= $dLen || $date[$di] !== $fc) {
                    return false;
                }
                ++$di;
                ++$fi;
                continue;
            }
            ++$fi;
            if ($fi >= $fLen) {
                return false;
            }
            $spec = $format[$fi];
            ++$fi;
            if ('%' === $spec) {
                if ($di >= $dLen || '%' !== $date[$di]) {
                    return false;
                }
                ++$di;
                continue;
            }
            if ('Y' === $spec) {
                $n = self::takeDigits($date, $di, $dLen, 4, 4);
                if (-1 === $n) {
                    return false;
                }
                $tmYear = self::digitValue($date, $di, $n) - 1900;
                $di = $n;
                $haveYear = 1;
                continue;
            }
            if ('y' === $spec) {
                $n = self::takeDigits($date, $di, $dLen, 1, 2);
                if (-1 === $n) {
                    return false;
                }
                $y = self::digitValue($date, $di, $n);
                $tmYear = $y <= 68 ? $y + 100 : $y;
                $di = $n;
                $haveYear = 1;
                continue;
            }
            if ('m' === $spec) {
                $n = self::takeDigits($date, $di, $dLen, 1, 2);
                if (-1 === $n) {
                    return false;
                }
                $m = self::digitValue($date, $di, $n);
                if ($m < 1 || $m > 12) {
                    return false;
                }
                $tmMon = $m - 1;
                $di = $n;
                $haveMon = 1;
                continue;
            }
            if ('d' === $spec) {
                $n = self::takeDigits($date, $di, $dLen, 1, 2);
                if (-1 === $n) {
                    return false;
                }
                $d = self::digitValue($date, $di, $n);
                if ($d < 1 || $d > 31) {
                    return false;
                }
                $tmMday = $d;
                $di = $n;
                $haveMday = 1;
                continue;
            }
            if ('H' === $spec) {
                $n = self::takeDigits($date, $di, $dLen, 1, 2);
                if (-1 === $n) {
                    return false;
                }
                $h = self::digitValue($date, $di, $n);
                if ($h > 23) {
                    return false;
                }
                $tmHour = $h;
                $di = $n;
                continue;
            }
            if ('M' === $spec) {
                $n = self::takeDigits($date, $di, $dLen, 1, 2);
                if (-1 === $n) {
                    return false;
                }
                $mi = self::digitValue($date, $di, $n);
                if ($mi > 59) {
                    return false;
                }
                $tmMin = $mi;
                $di = $n;
                continue;
            }
            if ('S' === $spec) {
                $n = self::takeDigits($date, $di, $dLen, 1, 2);
                if (-1 === $n) {
                    return false;
                }
                $s = self::digitValue($date, $di, $n);
                if ($s > 60) {
                    return false;
                }
                $tmSec = $s;
                $di = $n;
                continue;
            }
            if ('w' === $spec) {
                $n = self::takeDigits($date, $di, $dLen, 1, 1);
                if (-1 === $n) {
                    return false;
                }
                $w = self::digitValue($date, $di, $n);
                if ($w > 6) {
                    return false;
                }
                $tmWday = $w;
                $di = $n;
                continue;
            }

            return false;
        }

        if (1 === $haveYear && 1 === $haveMon && 1 === $haveMday) {
            $year = $tmYear + 1900;
            $mon = $tmMon + 1;
            if (!self::isValidYmd($year, $mon, $tmMday)) {
                return false;
            }
            $tmYday = self::dayOfYear($year, $mon, $tmMday);
            $tmWday = self::weekday($year, $mon, $tmMday);
        }

        $ht = new HashTable();
        self::addInt($ht, 'tm_sec', $tmSec);
        self::addInt($ht, 'tm_min', $tmMin);
        self::addInt($ht, 'tm_hour', $tmHour);
        self::addInt($ht, 'tm_mday', $tmMday);
        self::addInt($ht, 'tm_mon', $tmMon);
        self::addInt($ht, 'tm_year', $tmYear);
        self::addInt($ht, 'tm_wday', $tmWday);
        self::addInt($ht, 'tm_yday', $tmYday);
        $unparsed = \substr($date, $di);
        $slot = new Variable();
        $slot->string($unparsed);
        $ht->add('unparsed', $slot);

        return $ht;
    }

    /** Digits end index, or -1 on failure. */
    private static function takeDigits(string $date, int $di, int $dLen, int $min, int $max): int
    {
        $start = $di;
        $end = $di;
        while ($end < $dLen && ($end - $start) < $max) {
            $ch = $date[$end];
            if ($ch < '0' || $ch > '9') {
                break;
            }
            ++$end;
        }
        if (($end - $start) < $min) {
            return -1;
        }

        return $end;
    }

    private static function digitValue(string $date, int $start, int $end): int
    {
        $v = 0;
        $i = $start;
        while ($i < $end) {
            $v = ($v * 10) + (\ord($date[$i]) - 48);
            ++$i;
        }

        return $v;
    }

    private static function addInt(HashTable $ht, string $key, int $value): void
    {
        $slot = new Variable(Variable::TYPE_INTEGER);
        $slot->int($value);
        $ht->add($key, $slot);
    }

    private static function isValidYmd(int $year, int $mon, int $mday): bool
    {
        if ($mon < 1 || $mon > 12 || $mday < 1) {
            return false;
        }

        return $mday <= self::daysInMonth($year, $mon);
    }

    private static function daysInMonth(int $year, int $mon): int
    {
        if (2 === $mon) {
            return self::isLeap($year) ? 29 : 28;
        }
        if (4 === $mon || 6 === $mon || 9 === $mon || 11 === $mon) {
            return 30;
        }

        return 31;
    }

    private static function isLeap(int $year): bool
    {
        return (0 === $year % 4 && 0 !== $year % 100) || 0 === $year % 400;
    }

    private static function dayOfYear(int $year, int $mon, int $mday): int
    {
        $yday = $mday - 1;
        if ($mon > 1) {
            $yday += 31;
        }
        if ($mon > 2) {
            $yday += self::isLeap($year) ? 29 : 28;
        }
        if ($mon > 3) {
            $yday += 31;
        }
        if ($mon > 4) {
            $yday += 30;
        }
        if ($mon > 5) {
            $yday += 31;
        }
        if ($mon > 6) {
            $yday += 30;
        }
        if ($mon > 7) {
            $yday += 31;
        }
        if ($mon > 8) {
            $yday += 31;
        }
        if ($mon > 9) {
            $yday += 30;
        }
        if ($mon > 10) {
            $yday += 31;
        }
        if ($mon > 11) {
            $yday += 30;
        }

        return $yday;
    }

    /** Sakamoto — Sunday=0 … Saturday=6 (POSIX tm_wday). */
    private static function weekday(int $year, int $mon, int $mday): int
    {
        $y = $year;
        if ($mon < 3) {
            --$y;
        }
        $t = 4;
        if (1 === $mon) {
            $t = 0;
        } elseif (2 === $mon) {
            $t = 3;
        } elseif (3 === $mon) {
            $t = 2;
        } elseif (4 === $mon) {
            $t = 5;
        } elseif (5 === $mon) {
            $t = 0;
        } elseif (6 === $mon) {
            $t = 3;
        } elseif (7 === $mon) {
            $t = 5;
        } elseif (8 === $mon) {
            $t = 1;
        } elseif (9 === $mon) {
            $t = 4;
        } elseif (10 === $mon) {
            $t = 6;
        } elseif (11 === $mon) {
            $t = 2;
        }

        return (int) (($y + (int) ($y / 4) - (int) ($y / 100) + (int) ($y / 400) + $t + $mday) % 7);
    }
}
