<?php

declare(strict_types=1);

/**
 * VM-runtime number_format() (C-style locale subset; no PHP userland builtins).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

final class VmNumberFormat
{
    private const MAX_ARGS = 4;

    /**
     * php-src ext/standard/math.c — ZEND_PARSE_PARAMETERS_START(1, 4).
     *
     * PHP 8.4 forward profile allows a fifth positional only when it is a RoundingMode enum (#9438).
     */
    public static function assertArgCount(int $argc, ?Variable $fifthArg = null): void
    {
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'number_format() expects at least 1 argument, %d given',
                $argc
            ));
        }
        if ($argc <= self::MAX_ARGS) {
            return;
        }
        if (5 === $argc
            && CompilerVersion::supportsRoundingModeEnum()
            && null !== $fifthArg
            && null !== VmRoundMode::tryRoundModeInt($fifthArg->resolveIndirect())) {
            return;
        }

        throw new \ArgumentCountError(\sprintf(
            'number_format() expects at most %d arguments, %d given',
            self::MAX_ARGS,
            $argc
        ));
    }

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
        string $thousandsSeparator = ',',
        int $roundingMode = StdlibConstants::PHP_ROUND_HALF_UP
    ): string {
        // php-src ext/standard/math.c _php_math_number_format_ex: non-finite via %F, lowercased
        if (\is_nan($number)) {
            return 'nan';
        }
        if (\is_infinite($number)) {
            return 'inf';
        }

        // php-src ext/standard/math.c _php_math_number_format_ex (#15917):
        // _php_math_round($d, $dec, …) then $dec = MAX(0, $dec) for display precision.
        // Pre-8.3 ignores negative $decimals like 0.
        $roundPlaces = $decimals;
        if ($decimals < 0) {
            if (version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=')) {
                throw new \ValueError('number_format(): Argument #2 ($decimals) must be greater than or equal to 0');
            }
            if (!CompilerVersion::supportsNumberFormatNegativeDecimals()) {
                $roundPlaces = 0;
                $decimals = 0;
            } else {
                $decimals = 0;
            }
        }

        $negative = $number < 0.0;
        if ($negative) {
            $number = -$number;
        }

        $rounded = VmRound::mathRound($number, $roundPlaces, $roundingMode);
        $absRounded = $negative ? -$rounded : $rounded;

        if ($decimals > 0) {
            return self::formatWithSprintfFraction(
                $absRounded,
                $decimals,
                $decimalSeparator,
                $thousandsSeparator,
                $negative
            );
        }

        $intDigits = self::digitsFromInt((int) floor($absRounded));
        $result = self::insertThousands($intDigits, $thousandsSeparator);

        if ($negative) {
            return '-'.$result;
        }

        return $result;
    }

    /**
     * php-src ext/standard/number_format.c — snprintf / php_conv_floating_point for display precision.
     *
     * Integer extraction of the fractional part loses accuracy beyond ~14 decimal digits (IEEE double).
     */
    private static function formatWithSprintfFraction(
        float $absRounded,
        int $decimals,
        string $decimalSeparator,
        string $thousandsSeparator,
        bool $negative
    ): string {
        $formatted = \sprintf('%.'.$decimals.'f', $absRounded);
        $dotPos = \strpos($formatted, '.');
        if (false === $dotPos) {
            $intDigits = $formatted;
            $fracDigits = '';
        } else {
            $intDigits = VmString::byteSlice($formatted, 0, $dotPos);
            $fracDigits = VmString::byteSlice($formatted, $dotPos + 1);
        }

        $result = self::insertThousands($intDigits, $thousandsSeparator);
        if ('' !== $fracDigits) {
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
