<?php

declare(strict_types=1);

/**
 * VM-runtime sprintf() subset (%s, %d, %f, %%) without PHP userland builtins.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

final class VmSprintf
{
    private const MAX_OUTPUT = 4096;

    /**
     * @param list<Variable> $args
     */
    public static function format(string $format, array $args, ?Frame $frame = null): string
    {
        $out = '';
        $argIdx = 0;
        $len = VmString::byteLength($format);
        for ($pos = 0; $pos < $len; ++$pos) {
            $ch = $format[$pos];
            if ('%' !== $ch) {
                $out .= $ch;
                continue;
            }
            if ($pos + 1 >= $len) {
                throw new \LogicException('sprintf() trailing % in format string');
            }
            $spec = $format[++$pos];
            if ('%' === $spec) {
                $out .= '%';
                continue;
            }
            if ($argIdx >= \count($args)) {
                throw new \LogicException('sprintf() too few arguments for format string');
            }
            $var = $args[$argIdx++];
            switch ($spec) {
                case 's':
                    $out .= self::argToString($var, $frame);
                    break;
                case 'd':
                    $out .= self::intToDecimal(self::argToInt($var, $frame));
                    break;
                case 'f':
                    $out .= VmNumberFormat::format(self::argToFloat($var, $frame), 6, '.', '');
                    break;
                case 'b':
                    $out .= self::intToBinary(self::argToInt($var, $frame));
                    break;
                case 'x':
                    $out .= self::intToRadix(self::argToInt($var, $frame), 16, false);
                    break;
                case 'X':
                    $out .= self::intToRadix(self::argToInt($var, $frame), 16, true);
                    break;
                case 'o':
                    $out .= self::intToRadix(self::argToInt($var, $frame), 8, false);
                    break;
                case 'u':
                    $out .= self::intToUnsignedDecimal(self::argToInt($var, $frame));
                    break;
                case 'c':
                    $out .= self::intToChar(self::argToInt($var, $frame));
                    break;
                case 'e':
                    $out .= self::formatScientific(self::argToFloat($var, $frame), false);
                    break;
                case 'E':
                    $out .= self::formatScientific(self::argToFloat($var, $frame), true);
                    break;
                case 'g':
                    $out .= self::formatGeneral(self::argToFloat($var, $frame), false);
                    break;
                case 'G':
                    $out .= self::formatGeneral(self::argToFloat($var, $frame), true);
                    break;
                default:
                    throw new \LogicException(
                        'sprintf() unsupported conversion specifier %'.$spec.' in this compiler build'
                    );
            }
            if (VmString::byteLength($out) > self::MAX_OUTPUT) {
                throw new \LogicException('sprintf() output exceeds maximum length in this compiler build');
            }
        }

        return $out;
    }

    private static function argToString(Variable $var, ?Frame $frame = null): string
    {
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
            throw new \Error("Object of class {$enumClass->name} could not be converted to string");
        }
        switch ($var->type) {
            case Variable::TYPE_STRING:
                return $var->toString();
            case Variable::TYPE_INTEGER:
                return self::intToDecimal($var->toInt());
            case Variable::TYPE_FLOAT:
                return VmNumberFormat::format($var->toFloat(), 6, '.', '');
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? '1' : '';
            case Variable::TYPE_NULL:
                return '';
            default:
                throw new \LogicException('sprintf() %s requires a scalar value in this compiler build');
        }
    }

    private static function argToInt(Variable $var, ?Frame $frame = null): int
    {
        $enumInt = null !== $frame
            ? VmScalarType::tryEnumCaseToInt($frame, $var)
            : EnumCaseSupport::tryCastToInt($var);
        if (null !== $enumInt) {
            return $enumInt;
        }
        switch ($var->type) {
            case Variable::TYPE_INTEGER:
                return $var->toInt();
            case Variable::TYPE_FLOAT:
                return (int) $var->toFloat();
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? 1 : 0;
            case Variable::TYPE_NULL:
                return 0;
            case Variable::TYPE_STRING:
                return (int) $var->toString();
            default:
                throw new \LogicException('sprintf() %d requires a scalar value in this compiler build');
        }
    }

    private static function argToFloat(Variable $var, ?Frame $frame = null): float
    {
        $enumFloat = null !== $frame
            ? VmScalarType::tryEnumCaseToFloat($frame, $var)
            : EnumCaseSupport::tryCastToFloat($var);
        if (null !== $enumFloat) {
            return $enumFloat;
        }
        switch ($var->type) {
            case Variable::TYPE_FLOAT:
                return $var->toFloat();
            case Variable::TYPE_INTEGER:
                return (float) $var->toInt();
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? 1.0 : 0.0;
            case Variable::TYPE_NULL:
                return 0.0;
            case Variable::TYPE_STRING:
                return (float) $var->toString();
            default:
                throw new \LogicException('sprintf() %f requires a scalar value in this compiler build');
        }
    }

    private static function intToDecimal(int $value): string
    {
        if (0 === $value) {
            return '0';
        }
        $negative = $value < 0;
        if ($negative) {
            $value = -$value;
        }
        $digits = '';
        while ($value > 0) {
            $digits = \chr(48 + ($value % 10)).$digits;
            $value = (int) ($value / 10);
        }

        return $negative ? '-'.$digits : $digits;
    }

    /**
     * php-src sprintf.c — %b (unsigned machine-word bit pattern).
     */
    private static function intToBinary(int $value): string
    {
        return self::intToRadix($value, 2, false);
    }

    /**
     * @param 2|8|16 $base
     */
    private static function intToRadix(int $value, int $base, bool $upper): string
    {
        if (0 === $value) {
            return '0';
        }
        $chars = $upper ? '0123456789ABCDEF' : '0123456789abcdef';
        $bits = PHP_INT_SIZE * 8;
        $digits = '';
        $shift = $base === 2 ? 1 : ($base === 8 ? 3 : 4);
        $mask = (1 << $shift) - 1;
        $maxShift = (int) (intdiv($bits - 1, $shift) * $shift);
        for ($i = $maxShift; $i >= 0; $i -= $shift) {
            $digit = ($value >> $i) & $mask;
            if ('' !== $digits || 0 !== $digit) {
                $digits .= $chars[$digit];
            }
        }

        return '' === $digits ? '0' : $digits;
    }

    /** php-src sprintf.c — %u (zend_ulong decimal). */
    private static function intToUnsignedDecimal(int $value): string
    {
        if ($value >= 0) {
            return self::intToDecimal($value);
        }
        $dec = '0';
        foreach (str_split(self::intToRadix($value, 16, false)) as $hexDigit) {
            $dec = self::decimalMulAdd($dec, 16, (int) \hexdec($hexDigit));
        }

        return $dec;
    }

    private static function decimalMulAdd(string $decimal, int $multiplier, int $addend): string
    {
        $carry = $addend;
        $out = '';
        for ($i = \strlen($decimal) - 1; $i >= 0; --$i) {
            $digit = ((int) $decimal[$i]) * $multiplier + $carry;
            $out = (string) ($digit % 10).$out;
            $carry = intdiv($digit, 10);
        }
        while ($carry > 0) {
            $out = (string) ($carry % 10).$out;
            $carry = intdiv($carry, 10);
        }

        return '' === $out ? '0' : $out;
    }

    /** php-src sprintf.c — %c (ASCII code point). */
    private static function intToChar(int $value): string
    {
        return \chr($value & 0xFF);
    }

    /** php-src sprintf.c — %e / %E (default precision 6). */
    private static function formatScientific(float $value, bool $upper): string
    {
        if (\is_nan($value)) {
            return 'NAN';
        }
        if (\is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }
        $sign = $value < 0 ? '-' : '';
        $abs = \abs($value);
        if (0.0 === $abs) {
            return '0.000000'.($upper ? 'E' : 'e').'+0';
        }
        $exp = (int) \floor(\log10($abs));
        $mantissa = $abs / (10 ** $exp);
        $mant = VmNumberFormat::format($mantissa, 6, '.', '');
        $expChar = $upper ? 'E' : 'e';
        $expSign = $exp >= 0 ? '+' : '-';

        return $sign.$mant.$expChar.$expSign.\abs($exp);
    }

    /** php-src sprintf.c — %g / %G (default precision 6). */
    private static function formatGeneral(float $value, bool $upper): string
    {
        if (\is_nan($value)) {
            return 'NAN';
        }
        if (\is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }
        $abs = \abs($value);
        if (0.0 === $abs) {
            return '0';
        }
        if ($abs < 1e-4 || $abs >= 1e6) {
            return self::trimGeneralScientific(self::formatScientific($value, $upper));
        }
        $formatted = VmNumberFormat::format($value, 6, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = \rtrim(\rtrim($formatted, '0'), '.');
        }

        return $formatted;
    }

    private static function trimGeneralScientific(string $scientific): string
    {
        if (!preg_match('/^(-?)(\d+\.\d*?)([eE])([+-]\d+)$/', $scientific, $m)) {
            return $scientific;
        }
        $mantissa = \rtrim(\rtrim($m[2], '0'), '.');
        if ('' === $mantissa || '.' === $mantissa) {
            $mantissa = '0';
        }

        return $m[1].$mantissa.$m[3].$m[4];
    }
}
