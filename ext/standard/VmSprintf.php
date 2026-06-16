<?php

declare(strict_types=1);

/**
 * VM-runtime sprintf() subset (%s, %d, %f, %%, %n$ positional, width/flags, #3631, #9069).
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
        $argCount = \count($args);
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
            $parsed = self::parseConversionSpec($format, $pos + 1, $len);
            $pos = $parsed['nextPos'] - 1;
            if ($parsed['literalPercent']) {
                $out .= '%';
                continue;
            }

            $width = $parsed['width'];
            if ($parsed['widthFromArg']) {
                $widthVarIdx = self::resolveArgIndex($parsed['positional'], $argIdx, $argCount);
                $width = self::argToWidth($args[$widthVarIdx], $frame);
                if (null !== $parsed['positional']) {
                    $parsed['positional'] = null;
                }
            }

            $precision = $parsed['precision'];
            if ($parsed['precisionFromArg']) {
                $precVarIdx = self::resolveArgIndex($parsed['positional'], $argIdx, $argCount);
                $precision = self::argToPrecision($args[$precVarIdx], $frame);
                if (null !== $parsed['positional']) {
                    $parsed['positional'] = null;
                }
            }

            $varIdx = self::resolveArgIndex($parsed['positional'], $argIdx, $argCount);
            $converted = self::applyConversion(
                $parsed['spec'],
                $args[$varIdx],
                $frame,
                $precision
            );
            $out .= self::applyWidth(
                $converted,
                $width,
                $parsed['leftAdjust'],
                $parsed['zeroPad'],
                $parsed['spec']
            );
            if (VmString::byteLength($out) > self::MAX_OUTPUT) {
                throw new \LogicException('sprintf() output exceeds maximum length in this compiler build');
            }
        }

        return $out;
    }

    /**
     * Parse a conversion after '%' (php-src ext/standard/sprintf.c — positional, flags, width, precision).
     *
     * @return array{
     *     nextPos: int,
     *     literalPercent: bool,
     *     spec: string,
     *     positional: ?int,
     *     leftAdjust: bool,
     *     zeroPad: bool,
     *     width: ?int,
     *     widthFromArg: bool,
     *     precision: ?int,
     *     precisionFromArg: bool
     * }
     */
    private static function parseConversionSpec(string $format, int $start, int $len): array
    {
        $pos = $start;
        if ($pos < $len && '%' === $format[$pos]) {
            return [
                'nextPos' => $pos + 1,
                'literalPercent' => true,
                'spec' => '',
                'positional' => null,
                'leftAdjust' => false,
                'zeroPad' => false,
                'width' => null,
                'widthFromArg' => false,
                'precision' => null,
                'precisionFromArg' => false,
            ];
        }

        $positional = null;
        if ($pos < $len && \ctype_digit($format[$pos])) {
            $numStart = $pos;
            while ($pos < $len && \ctype_digit($format[$pos])) {
                ++$pos;
            }
            if ($pos < $len && '$' === $format[$pos]) {
                $argnum = (int) \substr($format, $numStart, $pos - $numStart);
                if ($argnum <= 0) {
                    throw new \ValueError(
                        'Argument number specifier must be greater than zero and less than 2147483647'
                    );
                }
                $positional = $argnum;
                ++$pos;
            } else {
                $pos = $numStart;
            }
        }

        $leftAdjust = false;
        $zeroPad = false;
        while ($pos < $len) {
            $flag = $format[$pos];
            if ('-' === $flag) {
                $leftAdjust = true;
                ++$pos;
                continue;
            }
            if ('0' === $flag) {
                $zeroPad = true;
                ++$pos;
                continue;
            }
            if (\in_array($flag, ['+', ' ', '#'], true)) {
                ++$pos;
                continue;
            }
            break;
        }

        $width = null;
        $widthFromArg = false;
        if ($pos < $len && '*' === $format[$pos]) {
            $widthFromArg = true;
            ++$pos;
        } elseif ($pos < $len && \ctype_digit($format[$pos])) {
            $widthStart = $pos;
            while ($pos < $len && \ctype_digit($format[$pos])) {
                ++$pos;
            }
            $width = (int) \substr($format, $widthStart, $pos - $widthStart);
        }

        $precision = null;
        $precisionFromArg = false;
        if ($pos < $len && '.' === $format[$pos]) {
            ++$pos;
            if ($pos < $len && '*' === $format[$pos]) {
                $precisionFromArg = true;
                ++$pos;
            } elseif ($pos < $len && \ctype_digit($format[$pos])) {
                $precStart = $pos;
                while ($pos < $len && \ctype_digit($format[$pos])) {
                    ++$pos;
                }
                $precision = (int) \substr($format, $precStart, $pos - $precStart);
            } else {
                $precision = 0;
            }
        }

        if ($pos >= $len) {
            throw new \LogicException('sprintf() trailing % in format string');
        }

        return [
            'nextPos' => $pos + 1,
            'literalPercent' => false,
            'spec' => $format[$pos],
            'positional' => $positional,
            'leftAdjust' => $leftAdjust,
            'zeroPad' => $zeroPad,
            'width' => $width,
            'widthFromArg' => $widthFromArg,
            'precision' => $precision,
            'precisionFromArg' => $precisionFromArg,
        ];
    }

    private static function resolveArgIndex(?int $positional, int &$sequentialIdx, int $argCount): int
    {
        if (null !== $positional) {
            if ($positional > $argCount) {
                throw new \ArgumentCountError(\sprintf(
                    '%d arguments are required, %d given',
                    $positional + 1,
                    $argCount + 1
                ));
            }

            return $positional - 1;
        }
        if ($sequentialIdx >= $argCount) {
            throw new \LogicException('sprintf() too few arguments for format string');
        }

        return $sequentialIdx++;
    }

    private static function argToWidth(Variable $var, ?Frame $frame): int
    {
        $width = self::argToInt($var, $frame);
        if ($width < 0) {
            throw new \ValueError('Width must be greater than zero and less than 2147483647');
        }

        return $width;
    }

    private static function argToPrecision(Variable $var, ?Frame $frame): int
    {
        $precision = self::argToInt($var, $frame);
        if ($precision < 0) {
            throw new \ValueError('Precision must be greater than or equal to 0 and less than 2147483647');
        }

        return $precision;
    }

    private static function applyWidth(
        string $value,
        ?int $width,
        bool $leftAdjust,
        bool $zeroPad,
        string $spec
    ): string {
        if (null === $width || $width <= VmString::byteLength($value)) {
            return $value;
        }
        $padLen = $width - VmString::byteLength($value);
        if ($leftAdjust) {
            return $value.str_repeat(' ', $padLen);
        }
        if ($zeroPad && 's' !== $spec) {
            if (str_starts_with($value, '-')) {
                return '-'.str_repeat('0', $padLen).substr($value, 1);
            }

            return str_repeat('0', $padLen).$value;
        }

        return str_repeat(' ', $padLen).$value;
    }

    private static function applyConversion(
        string $spec,
        Variable $var,
        ?Frame $frame,
        ?int $precision
    ): string {
        $floatPrec = $precision ?? 6;
        switch ($spec) {
            case 's':
                return self::argToString($var, $frame);
            case 'd':
                return self::intToDecimal(self::argToInt($var, $frame));
            case 'f':
                return VmNumberFormat::format(self::argToFloat($var, $frame), $floatPrec, '.', '');
            case 'b':
                return self::intToBinary(self::argToInt($var, $frame));
            case 'x':
                return self::intToRadix(self::argToInt($var, $frame), 16, false);
            case 'X':
                return self::intToRadix(self::argToInt($var, $frame), 16, true);
            case 'o':
                return self::intToRadix(self::argToInt($var, $frame), 8, false);
            case 'u':
                return self::intToUnsignedDecimal(self::argToInt($var, $frame));
            case 'c':
                return self::intToChar(self::argToInt($var, $frame));
            case 'e':
                return self::formatScientific(self::argToFloat($var, $frame), false, $floatPrec);
            case 'E':
                return self::formatScientific(self::argToFloat($var, $frame), true, $floatPrec);
            case 'g':
                return self::formatGeneral(self::argToFloat($var, $frame), false, $floatPrec);
            case 'G':
                return self::formatGeneral(self::argToFloat($var, $frame), true, $floatPrec);
            default:
                throw new \LogicException(
                    'sprintf() unsupported conversion specifier %'.$spec.' in this compiler build'
                );
        }
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
    private static function formatScientific(float $value, bool $upper, int $precision = 6): string
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
            $zeros = \str_repeat('0', $precision);

            return '0.'.$zeros.($upper ? 'E' : 'e').'+0';
        }
        $exp = (int) \floor(\log10($abs));
        $mantissa = $abs / (10 ** $exp);
        $mant = VmNumberFormat::format($mantissa, $precision, '.', '');
        $expChar = $upper ? 'E' : 'e';
        $expSign = $exp >= 0 ? '+' : '-';

        return $sign.$mant.$expChar.$expSign.\abs($exp);
    }

    /** php-src sprintf.c — %g / %G (default precision 6). */
    private static function formatGeneral(float $value, bool $upper, int $precision = 6): string
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
            return self::trimGeneralScientific(self::formatScientific($value, $upper, $precision));
        }
        $formatted = VmNumberFormat::format($value, $precision, '.', '');
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
