<?php

declare(strict_types=1);

/**
 * VM-runtime number_format() (C-style locale subset; no PHP userland builtins).
 */

namespace PHPCompiler\ext\standard;

final class VmNumberFormat
{
    public static function format(
        float $number,
        int $decimals = 0,
        string $decimalSeparator = '.',
        string $thousandsSeparator = ','
    ): string {
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
