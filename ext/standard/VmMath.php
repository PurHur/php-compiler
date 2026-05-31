<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/** Shared math coercion helpers for ext/standard (issue #3578) and base_convert (#3173). */
final class VmMath
{
    private const DIGITS = '0123456789abcdefghijklmnopqrstuvwxyz';

    public static function toFloat(Variable $v): float
    {
        if (Variable::TYPE_INTEGER === $v->type) {
            return (float) $v->toInt();
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            return $v->toFloat();
        }
        throw new \LogicException('Math builtins only support integers and floats in this compiler build');
    }

    public static function toInt(Variable $v): int
    {
        if (Variable::TYPE_INTEGER === $v->type) {
            return $v->toInt();
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            return (int) $v->toFloat();
        }
        throw new \LogicException('Math builtins only support integers and floats in this compiler build');
    }

    /**
     * pow() return typing — int when both operands are int with integral result (php-src math.c, issue #3678).
     */
    public static function applyPow(Variable $returnVar, Variable $base, Variable $exp): void
    {
        $returnVar->reset();
        if (Variable::TYPE_INTEGER === $base->type && Variable::TYPE_INTEGER === $exp->type) {
            $result = $base->toInt() ** $exp->toInt();
            if (\is_int($result)) {
                $returnVar->int($result);

                return;
            }
            $returnVar->float($result);

            return;
        }
        $returnVar->float(\pow(self::toFloat($base), self::toFloat($exp)));
    }

    /** @return float fractional part; writes integer part to $intPart (php-src modf). */
    public static function modf(float $num, float &$intPart): float
    {
        if (\is_nan($num) || \is_infinite($num)) {
            $intPart = $num;

            return $num;
        }
        $intPart = $num >= 0.0 ? \floor($num) : \ceil($num);

        return $num - $intPart;
    }

    /** @return float normalized fraction; writes binary exponent to $exp (php-src frexp). */
    public static function frexp(float $num, int &$exp): float
    {
        if (0.0 === $num) {
            $exp = 0;

            return 0.0;
        }
        if (\is_nan($num) || \is_infinite($num)) {
            $exp = 0;

            return $num;
        }
        $abs = \abs($num);
        $exp = (int) \floor(\log($abs, 2.0));
        $frac = $num / (2 ** $exp);
        if (\abs($frac) >= 1.0) {
            $frac /= 2.0;
            ++$exp;
        }
        if (0.0 !== $frac && \abs($frac) < 0.5) {
            $frac *= 2.0;
            --$exp;
        }

        return $frac;
    }

    public static function ldexp(float $num, int $exp): float
    {
        if (0.0 === $num || 0 === $exp) {
            return $num;
        }
        if (\is_nan($num) || \is_infinite($num)) {
            return $num;
        }

        return $num * (2 ** $exp);
    }

    /**
     * php-src: ext/standard/math.c — base_convert()
     */
    public static function baseConvert(string $number, int $fromBase, int $toBase): string
    {
        if ($fromBase < 2 || $fromBase > 36) {
            throw new \ValueError('base_convert(): Argument #2 ($from_base) must be between 2 and 36 (inclusive)');
        }
        if ($toBase < 2 || $toBase > 36) {
            throw new \ValueError('base_convert(): Argument #3 ($to_base) must be between 2 and 36 (inclusive)');
        }

        $value = self::baseToZval($number, $fromBase);

        return is_float($value)
            ? self::doubleToBase($value, $toBase)
            : self::longToBase((int) $value, $toBase);
    }

    /**
     * @return int|float
     */
    public static function baseToZval(string $str, int $base): int|float
    {
        $len = \strlen($str);
        $start = 0;
        $end = $len;

        while ($start < $end && \ctype_space($str[$start])) {
            ++$start;
        }
        while ($end > $start && \ctype_space($str[$end - 1])) {
            --$end;
        }

        if ($end - $start >= 2) {
            if (16 === $base && '0' === $str[$start] && ('x' === $str[$start + 1] || 'X' === $str[$start + 1])) {
                $start += 2;
            } elseif (8 === $base && '0' === $str[$start] && ('o' === $str[$start + 1] || 'O' === $str[$start + 1])) {
                $start += 2;
            } elseif (2 === $base && '0' === $str[$start] && ('b' === $str[$start + 1] || 'B' === $str[$start + 1])) {
                $start += 2;
            }
        }

        $num = 0;
        $fnum = 0.0;
        $mode = 0;
        $cutoff = intdiv(\PHP_INT_MAX, $base);
        $cutlim = \PHP_INT_MAX % $base;
        $invalidChars = 0;

        for ($i = $start; $i < $end; ++$i) {
            $c = $str[$i];
            if ($c >= '0' && $c <= '9') {
                $digit = (int) ($c - '0');
            } elseif ($c >= 'A' && $c <= 'Z') {
                $digit = (int) (\ord($c) - \ord('A') + 10);
            } elseif ($c >= 'a' && $c <= 'z') {
                $digit = (int) (\ord($c) - \ord('a') + 10);
            } else {
                ++$invalidChars;

                continue;
            }

            if ($digit >= $base) {
                ++$invalidChars;

                continue;
            }

            if (0 === $mode) {
                if ($num < $cutoff || ($num === $cutoff && $digit <= $cutlim)) {
                    $num = $num * $base + $digit;

                    continue;
                }
                $fnum = (float) $num;
                $mode = 1;
            }
            $fnum = $fnum * $base + $digit;
        }

        if ($invalidChars > 0) {
            @\trigger_error(
                'Invalid characters passed for attempted conversion, these have been ignored',
                \E_USER_DEPRECATED
            );
        }

        return 1 === $mode ? $fnum : $num;
    }

    public static function assignRadixToReturn(?Variable $returnVar, string $str, int $base): void
    {
        if (null === $returnVar) {
            return;
        }
        $result = self::baseToZval($str, $base);
        if (\is_int($result)) {
            $returnVar->int($result);
        } else {
            $returnVar->float($result);
        }
    }

    public static function longToBase(int $arg, int $base): string
    {
        if ($base < 2 || $base > 36) {
            return '';
        }

        if (0 === $arg) {
            return '0';
        }

        $negative = $arg < 0;
        $n = $negative ? abs($arg) : $arg;

        $buf = '';
        while ($n > 0) {
            $buf = self::DIGITS[$n % $base].$buf;
            $n = intdiv($n, $base);
        }

        return $negative ? '-'.$buf : $buf;
    }

    public static function doubleToBase(float $fvalue, int $base): string
    {
        if ($base < 2 || $base > 36) {
            return '';
        }

        if ($fvalue === \INF || $fvalue === -\INF) {
            throw new \ValueError(\sprintf('An infinite value cannot be converted to base %d', $base));
        }

        $fvalue = floor($fvalue);
        if (0.0 === $fvalue) {
            return '0';
        }

        $negative = $fvalue < 0.0;
        if ($negative) {
            $fvalue = -$fvalue;
        }

        $buf = '';
        while ($fvalue >= 1.0) {
            $digit = (int) fmod($fvalue, (float) $base);
            $buf = self::DIGITS[$digit].$buf;
            $fvalue /= (float) $base;
        }

        return $negative ? '-'.$buf : $buf;
    }
}
