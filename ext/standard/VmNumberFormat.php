<?php

declare(strict_types=1);

/**
 * VM-runtime number_format() (C-style locale subset; no PHP userland builtins).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

final class VmNumberFormat
{
    private const MAX_ARGS = 4;

    /**
     * php-src ext/standard/basic_functions.stub.php — number_format(…): string (arity 1–4).
     *
     * RoundingMode is not a number_format parameter (php-src math.c / #23575; re-#16330, #9438).
     */
    public static function assertArgCount(int $argc): void
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
    public static function coerceFloat(Variable $value, ?Frame $frame = null): float
    {
        $value = $value->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($value)) {
            $enumClass = EnumCaseSupport::enumClassForCaseVariable($value);
            $given = null !== $enumClass ? $enumClass->name : 'object';

            throw new \TypeError(self::numTypeError($given));
        }
        switch ($value->type) {
            case Variable::TYPE_NULL:
                if (null !== $frame && InternalStrictArg::isCallerStrict($frame)) {
                    throw new \TypeError(self::numTypeError('null'));
                }
                // Z_PARAM_DOUBLE $num: E_DEPRECATED then coerce to 0.0 on all profiles
                // including PROFILE=8.4 (#21429, reverts #21379 TypeError; php-src formatted_print.c).
                VmNullNumberParamDeprecation::emit($frame, 'number_format', 1, 'num', 'float');

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
            // php-src basic_functions.stub.php — int|float $num; TypeError cites union (#29976).
            // Weak null DEP still says "float" (Zend ReflectionArgInfo); keep emit(..., 'float').
            'number_format(): Argument #1 ($num) must be of type int|float, %s given',
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

        // php-src ext/standard/math.c _php_math_number_format_ex (#15917, #27899):
        // _php_math_round($d, $dec, …) then $dec = MAX(0, $dec) for display precision.
        // Pre-8.3 / reference harness ignores negative $decimals like 0 (Zend 8.2).
        $roundPlaces = $decimals;
        if ($decimals < 0) {
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

        // php-src ext/standard/math.c _php_math_number_format_ex (#23980):
        // after round on the absolute value, clear the sign when magnitude is zero
        // so number_format(-0.004, 2) is "0.00" not "-0.00".
        if ($negative && 0.0 == $rounded) {
            $negative = false;
        }

        // php-src formats %.*F on the absolute rounded value (not a re-signed float).
        // Integer frac extraction breaks past ~14 decimals (IEEE double); reuse dtoa fcvt path (#18525).
        $formatted = VmFloatDtoa::formatSprintfF($rounded, $decimals);
        if ('' !== $formatted && '-' === $formatted[0]) {
            $formatted = \substr($formatted, 1);
            $negative = true;
        }

        $dotPos = \strpos($formatted, '.');
        if (false === $dotPos) {
            $intDigits = '' === $formatted ? '0' : $formatted;
            $fracDigits = '';
        } else {
            $intDigits = \substr($formatted, 0, $dotPos);
            if ('' === $intDigits) {
                $intDigits = '0';
            }
            $fracDigits = \substr($formatted, $dotPos + 1);
        }

        $result = self::insertThousands($intDigits, $thousandsSeparator);

        if ($decimals > 0) {
            while (VmString::byteLength($fracDigits) < $decimals) {
                $fracDigits .= '0';
            }
            $result .= $decimalSeparator.$fracDigits;
        }

        if ($negative) {
            return '-'.$result;
        }

        return $result;
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
