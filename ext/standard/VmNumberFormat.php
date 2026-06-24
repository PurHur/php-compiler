<?php

declare(strict_types=1);

/**
 * VM-runtime number_format() (C-style locale subset; no PHP userland builtins).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

final class VmNumberFormat
{
    /**
     * Coerce number_format() argument #1 to float (php-src ext/standard/number_format.c).
     *
     * @throws \TypeError when the value is not int, float, or a numeric string
     */
    public static function coerceFloat(Variable $value): float
    {
        $value = $value->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($value)) {
            $enumClass = EnumCaseSupport::enumClassForCaseVariable($value);
            $given = null !== $enumClass ? $enumClass->name : 'object';

            throw new \TypeError(self::numTypeError($given));
        }
        switch ($value->type) {
            case Variable::TYPE_NULL:
                return 0.0;
            case Variable::TYPE_INTEGER:
                return (float) $value->toInt();
            case Variable::TYPE_FLOAT:
                return $value->toFloat();
            case Variable::TYPE_STRING:
                $s = $value->toString();
                if (!\is_numeric($s)) {
                    throw new \TypeError(self::numTypeError('string'));
                }

                return (float) $s;
            case Variable::TYPE_OBJECT:
                throw new \TypeError(self::numTypeError($value->toObject()->class->name));
            default:
                throw new \TypeError(self::numTypeError(VmParseStr::zendTypeLabel($value)));
        }
    }

    private static function numTypeError(string $given): string
    {
        return \sprintf(
            'number_format(): Argument #1 ($num) must be of type float, %s given',
            $given
        );
    }

    public static function format(
        float $number,
        int $decimals = 0,
        string $decimalSeparator = '.',
        string $thousandsSeparator = ','
    ): string {
        // php-src ext/standard/math.c _php_math_number_format_ex: non-finite via %F, lowercased
        if (\is_nan($number)) {
            return 'nan';
        }
        if (\is_infinite($number)) {
            return 'inf';
        }

        $negative = $number < 0.0;
        if ($negative) {
            $number = -$number;
        }

        $pow = 1;
        for ($i = 0; $i < $decimals; ++$i) {
            $pow *= 10;
        }

        if ($decimals > 0) {
            $rounded = round($number, $decimals);
        } else {
            $rounded = round($number, 0);
        }

        $intPart = (int) floor($rounded);
        $fracPart = 0;
        if ($decimals > 0) {
            $fracPart = (int) round(($rounded - (float) $intPart) * $pow);
            if ($fracPart >= $pow) {
                ++$intPart;
                $fracPart = 0;
            }
        }

        $intDigits = self::digitsFromInt($intPart);
        $result = self::insertThousands($intDigits, $thousandsSeparator);

        if ($decimals > 0) {
            $fracDigits = self::padLeft(self::digitsFromInt($fracPart), $decimals, '0');
            $result .= $decimalSeparator.$fracDigits;
        }

        if ($negative) {
            return '-'.$result;
        }

        return $result;
    }

    private static function digitsFromInt(int $value): string
    {
        if (0 === $value) {
            return '0';
        }
        $digits = '';
        while ($value > 0) {
            $digits = \chr(48 + ($value % 10)).$digits;
            $value = (int) ($value / 10);
        }

        return $digits;
    }

    private static function padLeft(string $digits, int $length, string $pad): string
    {
        while (VmString::byteLength($digits) < $length) {
            $digits = $pad.$digits;
        }

        return $digits;
    }

    private static function insertThousands(string $digits, string $separator): string
    {
        $len = VmString::byteLength($digits);
        if ($len <= 3 || '' === $separator) {
            return $digits;
        }
        $firstGroup = $len % 3;
        if (0 === $firstGroup) {
            $firstGroup = 3;
        }
        $out = VmString::byteSlice($digits, 0, $firstGroup);
        for ($i = $firstGroup; $i < $len; $i += 3) {
            $out .= $separator.VmString::byteSlice($digits, $i, 3);
        }

        return $out;
    }
}
