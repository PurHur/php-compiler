<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\NativeDateMalformedIntervalException;

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
        $message = 'Unknown or bad format ('.$spec.')';
        if (CompilerVersion::advertisesDateExceptionHierarchy()) {
            throw new NativeDateMalformedIntervalException($message);
        }
        throw new \Exception($message);
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
            // php-src timelib: empty input → position char is a space + "Empty string" (#29290).
            $warning = 'Unknown or bad format () at position 0 ( ): Empty string';

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

        // php-src timelib relative text — createFromDateString accepts phrases DateTime::modify does (#23936).
        $relativeText = self::tryParseRelativeTextInterval($spec);
        if (null !== $relativeText) {
            return $relativeText;
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
            [$field, $consumed, $scale] = $unitMatch;
            $pos += $consumed;

            switch ($field) {
                case 'y':
                    $y += $num;
                    break;
                case 'm':
                    $m += $num;
                    break;
                case 'd':
                    $d += (int) ($num * $scale);
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
                case 'f':
                    // millisecond(s)/ms/msec → 1e-3; microsecond(s)/usec → 1e-6 (#26694)
                    $f += ((float) $num) * $scale;
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

    /**
     * Map timelib relative phrases to DateInterval bags (php-src createFromDateString; #23936, #27954).
     *
     * Observable Zend 8.2: "last day of next month" / "next month" → m=1; weekday/"this week"
     * phrases → zero bag (special weekday stored internally; public props stay 0). Bare
     * yesterday/tomorrow/next|last|previous day use signed $d with invert=0 (#27954).
     *
     * @return array{y: int, m: int, d: int, h: int, i: int, s: int, f: float, invert: int}|null
     */
    private static function tryParseRelativeTextInterval(string $spec): ?array
    {
        $zero = ['y' => 0, 'm' => 0, 'd' => 0, 'h' => 0, 'i' => 0, 's' => 0, 'f' => 0.0, 'invert' => 0];

        // Bare day words — timelib parse_date.re yesterday/tomorrow (#27954).
        if (1 === preg_match('/^tomorrow$/i', $spec)) {
            $bag = $zero;
            $bag['d'] = 1;

            return $bag;
        }
        if (1 === preg_match('/^yesterday$/i', $spec)) {
            $bag = $zero;
            $bag['d'] = -1;

            return $bag;
        }
        // "next/last/previous/this day(s)" — signed $d, invert stays 0 (Zend; #27954).
        if (1 === preg_match('/^(next|last|this|previous)\s+days?$/i', $spec, $matches)) {
            $when = strtolower($matches[1]);
            if ('this' === $when) {
                return $zero;
            }
            $bag = $zero;
            $bag['d'] = ('last' === $when || 'previous' === $when) ? -1 : 1;

            return $bag;
        }

        if (1 === preg_match(
            '/^(?:(?:first|last)\s+day\s+of\s+)?(next|last|this|previous)\s+month$/i',
            $spec,
            $matches
        )) {
            $when = strtolower($matches[1]);
            if ('this' === $when) {
                return $zero;
            }
            $bag = $zero;
            $bag['m'] = 1;
            if ('last' === $when || 'previous' === $when) {
                $bag['invert'] = 1;
            }

            return $bag;
        }
        if (1 === preg_match('/^(next|last|this|previous)\s+year$/i', $spec, $matches)) {
            $when = strtolower($matches[1]);
            if ('this' === $when) {
                return $zero;
            }
            $bag = $zero;
            $bag['y'] = 1;
            if ('last' === $when || 'previous' === $when) {
                $bag['invert'] = 1;
            }

            return $bag;
        }
        if (1 === preg_match('/^(next|last|this|previous)\s+week$/i', $spec, $matches)) {
            $when = strtolower($matches[1]);
            if ('this' === $when) {
                return $zero;
            }
            $bag = $zero;
            $bag['d'] = 7;
            if ('last' === $when || 'previous' === $when) {
                $bag['invert'] = 1;
            }

            return $bag;
        }
        // Weekday / "monday this week" — Zend returns a zero bag (special weekday flag).
        if (1 === preg_match(
            '/^(?:next|last|previous|this)\s+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/i',
            $spec
        )) {
            return $zero;
        }
        if (1 === preg_match(
            '/^(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/i',
            $spec
        )) {
            return $zero;
        }
        if (1 === preg_match(
            '/^(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)\s+(?:this|next|last|previous)\s+week$/i',
            $spec
        )) {
            return $zero;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: int, 2: float|int}|null field, consumed chars, scale (weeks→d, ms/us→f)
     */
    private static function matchFromDateStringUnit(string $spec, int $pos): ?array
    {
        // Longest-first: milliseconds before ms; microseconds before usec (timelib relative units).
        static $units = [
            'milliseconds' => ['f', 0.001],
            'millisecond' => ['f', 0.001],
            'microseconds' => ['f', 0.000001],
            'microsecond' => ['f', 0.000001],
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
            'msec' => ['f', 0.001],
            'usec' => ['f', 0.000001],
            'ms' => ['f', 0.001],
        ];
        $tail = strtolower(\substr($spec, $pos));
        $best = null;
        foreach ($units as $word => [$field, $scale]) {
            if (!str_starts_with($tail, $word)) {
                continue;
            }
            $next = $pos + \strlen($word);
            if ($next < \strlen($spec) && \ctype_alpha($spec[$next])) {
                continue;
            }
            if (null === $best || \strlen($word) > $best[1]) {
                $best = [$field, \strlen($word), $scale];
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
