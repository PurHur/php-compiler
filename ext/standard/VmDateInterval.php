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
}
