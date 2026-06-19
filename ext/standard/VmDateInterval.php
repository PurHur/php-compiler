<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * DateInterval ISO-8601 duration parse/format (php-src ext/date/php_date.c, issue #7278).
 */
final class VmDateInterval
{
    /**
     * @return array{y: int, m: int, d: int, h: int, i: int, s: int, f: float, invert: int}
     */
    public static function parseSpec(string $spec): array
    {
        $len = \strlen($spec);
        if ($len < 2 || 'P' !== $spec[0]) {
            self::throwBadFormat($spec);
        }

        $pos = 1;
        $y = $m = $d = $h = $i = $s = 0;
        $f = 0.0;
        $hasValue = false;

        while ($pos < $len && 'T' !== $spec[$pos]) {
            $parsed = self::readNumber($spec, $pos);
            if (null === $parsed) {
                break;
            }
            [$num, $pos] = $parsed;
            if ($pos >= $len) {
                self::throwBadFormat($spec);
            }
            $unit = $spec[$pos];
            ++$pos;
            switch ($unit) {
                case 'Y':
                    $y = $num;
                    $hasValue = true;
                    break;
                case 'M':
                    $m = $num;
                    $hasValue = true;
                    break;
                case 'D':
                    $d = $num;
                    $hasValue = true;
                    break;
                case 'W':
                    $d += $num * 7;
                    $hasValue = true;
                    break;
                default:
                    self::throwBadFormat($spec);
            }
        }

        if ($pos < $len) {
            if ('T' !== $spec[$pos]) {
                self::throwBadFormat($spec);
            }
            ++$pos;
            while ($pos < $len) {
                $parsed = self::readNumber($spec, $pos, true);
                if (null === $parsed) {
                    self::throwBadFormat($spec);
                }
                [$num, $pos] = $parsed;
                if ($pos >= $len) {
                    self::throwBadFormat($spec);
                }
                $unit = $spec[$pos];
                ++$pos;
                switch ($unit) {
                    case 'H':
                        $h = $num;
                        $hasValue = true;
                        break;
                    case 'M':
                        $i = $num;
                        $hasValue = true;
                        break;
                    case 'S':
                        if (\is_float($num)) {
                            $whole = (int) \floor($num);
                            $s = $whole;
                            $f = $num - (float) $whole;
                        } else {
                            $s = $num;
                        }
                        $hasValue = true;
                        break;
                    default:
                        self::throwBadFormat($spec);
                }
            }
        }

        if (!$hasValue) {
            self::throwBadFormat($spec);
        }

        return [
            'y' => $y,
            'm' => $m,
            'd' => $d,
            'h' => $h,
            'i' => $i,
            's' => $s,
            'f' => $f,
            'invert' => 0,
        ];
    }

    /**
     * @param array{y: int, m: int, d: int, h: int, i: int, s: int, f: float, invert: int, days: bool|int} $state
     */
    public static function format(array $state, string $format): string
    {
        $out = '';
        $len = \strlen($format);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $format[$i];
            if ('%' !== $ch) {
                $out .= $ch;

                continue;
            }
            if ($i + 1 >= $len) {
                $out .= '%';

                continue;
            }
            $code = $format[++$i];
            switch ($code) {
                case 'y':
                    $out .= (string) $state['y'];
                    break;
                case 'Y':
                    $out .= \sprintf('%02d', $state['y']);
                    break;
                case 'm':
                    $out .= (string) $state['m'];
                    break;
                case 'M':
                    $out .= \sprintf('%02d', $state['m']);
                    break;
                case 'd':
                    $out .= (string) $state['d'];
                    break;
                case 'D':
                    $out .= \sprintf('%02d', $state['d']);
                    break;
                case 'h':
                    $out .= (string) $state['h'];
                    break;
                case 'H':
                    $out .= \sprintf('%02d', $state['h']);
                    break;
                case 'i':
                    $out .= (string) $state['i'];
                    break;
                case 'I':
                    $out .= \sprintf('%02d', $state['i']);
                    break;
                case 's':
                    $out .= (string) $state['s'];
                    break;
                case 'S':
                    $out .= \sprintf('%02d', $state['s']);
                    break;
                case 'f':
                    $micro = (int) \round($state['f'] * 1_000_000);
                    $out .= (string) $micro;
                    break;
                case 'a':
                    $days = $state['days'];
                    $out .= \is_int($days) ? (string) $days : '(unknown)';
                    break;
                case 'R':
                    $out .= 0 !== $state['invert'] ? '-' : '+';
                    break;
                case 'r':
                    if (0 !== $state['invert']) {
                        $out .= '-';
                    }
                    break;
                case '%':
                    $out .= '%';
                    break;
                default:
                    $out .= '%'.$code;
                    break;
            }
        }

        return $out;
    }

    /** @return array{0: int|float, 1: int}|null */
    private static function readNumber(string $spec, int $pos, bool $allowFloat = false): ?array
    {
        $len = \strlen($spec);
        if ($pos >= $len || !\ctype_digit($spec[$pos])) {
            return null;
        }
        $start = $pos;
        while ($pos < $len && \ctype_digit($spec[$pos])) {
            ++$pos;
        }
        if ($allowFloat && $pos < $len && '.' === $spec[$pos]) {
            ++$pos;
            if ($pos >= $len || !\ctype_digit($spec[$pos])) {
                return null;
            }
            while ($pos < $len && \ctype_digit($spec[$pos])) {
                ++$pos;
            }

            return [(float) \substr($spec, $start, $pos - $start), $pos];
        }

        return [(int) \substr($spec, $start, $pos - $start), $pos];
    }

    private static function throwBadFormat(string $spec): never
    {
        throw new \Exception('Unknown or bad format ('.$spec.')');
    }

    /**
     * Parse relative interval strings for date_interval_create_from_date_string() (#4606).
     *
     * php-src: ext/date/lib/interval.c — timelib_parse_from_string (relative units only; not ISO P…).
     *
     * @return array{y: int, m: int, d: int, h: int, i: int, s: int, f: float, invert: int}|null
     */
    public static function parseFromDateString(string $input, ?string &$warning = null): ?array
    {
        $warning = null;
        $baseOffset = \strlen($input) - \strlen(ltrim($input));
        $spec = ltrim($input);
        $len = \strlen($spec);
        if (0 === $len) {
            $warning = self::fromDateStringWarning($input, 0, 'The timezone could not be found in the database');

            return null;
        }

        if ('P' === $spec[0]) {
            $pos = 1;
            if ($pos < $len && \ctype_digit($spec[$pos])) {
                $warning = self::fromDateStringWarning($input, $baseOffset + $pos, 'Unexpected character');
            } else {
                $warning = self::fromDateStringWarning($input, $baseOffset, 'The timezone could not be found in the database');
            }

            return null;
        }

        $y = $m = $d = $h = $i = $s = 0;
        $f = 0.0;
        $hasValue = false;
        $pos = 0;

        while ($pos < $len) {
            while ($pos < $len && \ctype_space($spec[$pos])) {
                ++$pos;
            }
            if ($pos >= $len) {
                break;
            }

            $sign = 1;
            if ('+' === $spec[$pos] || '-' === $spec[$pos]) {
                if ('-' === $spec[$pos]) {
                    $sign = -1;
                }
                ++$pos;
                while ($pos < $len && \ctype_space($spec[$pos])) {
                    ++$pos;
                }
            }
            if ($pos >= $len || !\ctype_digit($spec[$pos])) {
                $warning = self::fromDateStringWarning(
                    $input,
                    $baseOffset + $pos,
                    'The timezone could not be found in the database'
                );

                return null;
            }
            $numStart = $pos;
            while ($pos < $len && \ctype_digit($spec[$pos])) {
                ++$pos;
            }
            $num = (int) \substr($spec, $numStart, $pos - $numStart) * $sign;

            while ($pos < $len && \ctype_space($spec[$pos])) {
                ++$pos;
            }
            if ($pos >= $len || !\ctype_alpha($spec[$pos])) {
                $warning = self::fromDateStringWarning(
                    $input,
                    $baseOffset + $pos,
                    'The timezone could not be found in the database'
                );

                return null;
            }

            $unitMatch = self::matchFromDateStringUnit($spec, $pos);
            if (null === $unitMatch) {
                $warning = self::fromDateStringWarning(
                    $input,
                    $baseOffset + $pos,
                    'The timezone could not be found in the database'
                );

                return null;
            }
            [$field, $consumed, $weekFactor] = $unitMatch;
            $pos += $consumed;

            switch ($field) {
                case 'y':
                    $y += $num;
                    break;
                case 'm':
                    $m += $num;
                    break;
                case 'd':
                    $d += $num * $weekFactor;
                    break;
                case 'h':
                    $h += $num;
                    break;
                case 'i':
                    $i += $num;
                    break;
                case 's':
                    $s += $num;
                    break;
            }
            $hasValue = true;
        }

        if (!$hasValue) {
            $warning = self::fromDateStringWarning($input, $baseOffset, 'The timezone could not be found in the database');

            return null;
        }

        return [
            'y' => $y,
            'm' => $m,
            'd' => $d,
            'h' => $h,
            'i' => $i,
            's' => $s,
            'f' => $f,
            'invert' => 0,
        ];
    }

    /** @return array{0: string, 1: int, 2: int}|null field, consumed chars, week multiplier */
    private static function matchFromDateStringUnit(string $spec, int $pos): ?array
    {
        static $units = [
            'years' => ['y', 1],
            'year' => ['y', 1],
            'months' => ['m', 1],
            'month' => ['m', 1],
            'weeks' => ['d', 7],
            'week' => ['d', 7],
            'days' => ['d', 1],
            'day' => ['d', 1],
            'hours' => ['h', 1],
            'hour' => ['h', 1],
            'minutes' => ['i', 1],
            'minute' => ['i', 1],
            'seconds' => ['s', 1],
            'second' => ['s', 1],
        ];
        $tail = strtolower(\substr($spec, $pos));
        $best = null;
        foreach ($units as $word => [$field, $factor]) {
            if (!str_starts_with($tail, $word)) {
                continue;
            }
            $next = $pos + \strlen($word);
            if ($next < \strlen($spec) && \ctype_alpha($spec[$next])) {
                continue;
            }
            if (null === $best || \strlen($word) > $best[1]) {
                $best = [$field, \strlen($word), $factor];
            }
        }

        return $best;
    }

    public static function fromDateStringWarning(string $input, int $pos, string $reason): string
    {
        $char = $pos < \strlen($input) ? $input[$pos] : '?';

        return \sprintf(
            'Unknown or bad format (%s) at position %d (%s): %s',
            $input,
            $pos,
            $char,
            $reason
        );
    }
}
