<?php

declare(strict_types=1);

/**
 * VM-runtime sprintf() subset (%s, %d, %f, %%, %n$ positional, width/flags,
 * %'<char> pad, %* / %.* / %N$*M$ / %N$.*M$ star args, #3631, #9069, #22833, #22834).
 * %a/%A are unknown on Zend (ValueError; #29085 / #29059 — retract #9059 phantom).
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
     * @param int            $nbAdditionalParameters php-src formatted_print.c — fixed args before
     *                                               value argv (sprintf/printf=1, fprintf=2);
     *                                               ignored when $arrayArgs (vsprintf family = -1)
     */
    public static function format(
        string $format,
        array $args,
        ?Frame $frame = null,
        bool $arrayArgs = false,
        int $nbAdditionalParameters = 1
    ): string {
        $out = '';
        $argIdx = 0;
        $argCount = \count($args);
        // php-src max_missing_argnum — defer ArgumentCountError until end of format (#24661).
        $maxMissingArgnum = -1;
        $len = VmString::byteLength($format);
        for ($pos = 0; $pos < $len; ++$pos) {
            $ch = $format[$pos];
            if ('%' !== $ch) {
                $out .= $ch;
                continue;
            }
            $parsed = self::parseConversionSpec($format, $pos + 1, $len);
            $pos = $parsed['nextPos'] - 1;
            if ($parsed['literalPercent']) {
                $out .= '%';
                continue;
            }

            $width = $parsed['width'];
            if ($parsed['widthFromArg']) {
                // php-src formatted_print.c — width * uses *N$ or sequential currarg, not value argnum (#22834).
                $widthVarIdx = self::resolveArgIndexOrMissing(
                    $parsed['widthPositional'],
                    $argIdx,
                    $argCount,
                    $maxMissingArgnum
                );
                if (null === $widthVarIdx) {
                    // php-src: missing width → continue without reserving the value arg.
                    continue;
                }
                $width = self::argToWidth($args[$widthVarIdx], $frame);
            }

            $precision = $parsed['precision'];
            if ($parsed['precisionFromArg']) {
                $precVarIdx = self::resolveArgIndexOrMissing(
                    $parsed['precisionPositional'],
                    $argIdx,
                    $argCount,
                    $maxMissingArgnum
                );
                if (null === $precVarIdx) {
                    // php-src: missing precision → continue without reserving the value arg.
                    continue;
                }
                $precision = self::argToPrecision($args[$precVarIdx], $frame);
            }

            // php-src: value arg is reserved before the type specifier; incomplete trailing %
            // therefore reports ArgumentCountError first, else ValueError (#24661).
            $varIdx = self::resolveArgIndexOrMissing(
                $parsed['positional'],
                $argIdx,
                $argCount,
                $maxMissingArgnum
            );
            if (null === $varIdx) {
                continue;
            }
            if ($parsed['incomplete']) {
                throw new \ValueError('Missing format specifier at end of string');
            }
            $converted = self::applyConversion(
                $parsed['spec'],
                $args[$varIdx],
                $frame,
                $precision,
                $parsed['showSign']
            );
            $out .= self::applyWidth(
                $converted,
                $width,
                $parsed['leftAdjust'],
                $parsed['padding'],
                $parsed['spec']
            );
            if (VmString::byteLength($out) > self::MAX_OUTPUT) {
                throw new \LogicException('sprintf() output exceeds maximum length in this compiler build');
            }
        }

        if ($maxMissingArgnum >= 0) {
            self::throwTooFewArgs(
                $arrayArgs,
                $maxMissingArgnum + 1,
                $argCount,
                $nbAdditionalParameters
            );
        }

        return $out;
    }

    /**
     * Parse a conversion after '%' (php-src ext/standard/formatted_print.c — positional, flags, width, precision).
     *
     * @return array{
     *     nextPos: int,
     *     literalPercent: bool,
     *     incomplete: bool,
     *     spec: string,
     *     positional: ?int,
     *     leftAdjust: bool,
     *     padding: string,
     *     showSign: ?string,
     *     width: ?int,
     *     widthFromArg: bool,
     *     widthPositional: ?int,
     *     precision: ?int,
     *     precisionFromArg: bool,
     *     precisionPositional: ?int
     * }
     */
    private static function parseConversionSpec(string $format, int $start, int $len): array
    {
        $pos = $start;
        if ($pos < $len && '%' === $format[$pos]) {
            return [
                'nextPos' => $pos + 1,
                'literalPercent' => true,
                'incomplete' => false,
                'spec' => '',
                'positional' => null,
                'leftAdjust' => false,
                'padding' => ' ',
                'showSign' => null,
                'width' => null,
                'widthFromArg' => false,
                'widthPositional' => null,
                'precision' => null,
                'precisionFromArg' => false,
                'precisionPositional' => null,
            ];
        }

        // php-src php_sprintf_get_argnum — leading N$ selects the value argument.
        [$positional, $pos] = self::consumeOptionalArgnum($format, $pos, $len);

        $leftAdjust = false;
        $padding = ' ';
        $showSign = null;
        while ($pos < $len) {
            $flag = $format[$pos];
            if ('-' === $flag) {
                $leftAdjust = true;
                ++$pos;
                continue;
            }
            if (' ' === $flag || '0' === $flag) {
                $padding = $flag;
                ++$pos;
                continue;
            }
            if ('+' === $flag) {
                $showSign = '+';
                ++$pos;
                continue;
            }
            // php-src formatted_print.c — %'<char> custom pad (issue #22833).
            if ("'" === $flag) {
                ++$pos;
                if ($pos >= $len) {
                    throw new \ValueError('Missing padding character');
                }
                $padding = $format[$pos];
                ++$pos;
                continue;
            }
            if ('#' === $flag) {
                throw new \ValueError('Unknown format specifier "#"');
            }
            break;
        }

        $width = null;
        $widthFromArg = false;
        $widthPositional = null;
        if ($pos < $len && '*' === $format[$pos]) {
            $widthFromArg = true;
            ++$pos;
            // php-src: after '*', optional N$ width argnum (issue #22834).
            [$widthPositional, $pos] = self::consumeOptionalArgnum($format, $pos, $len);
        } elseif ($pos < $len && \ctype_digit($format[$pos])) {
            $widthStart = $pos;
            while ($pos < $len && \ctype_digit($format[$pos])) {
                ++$pos;
            }
            $width = (int) \substr($format, $widthStart, $pos - $widthStart);
        }

        $precision = null;
        $precisionFromArg = false;
        $precisionPositional = null;
        if ($pos < $len && '.' === $format[$pos]) {
            ++$pos;
            if ($pos < $len && '*' === $format[$pos]) {
                $precisionFromArg = true;
                ++$pos;
                [$precisionPositional, $pos] = self::consumeOptionalArgnum($format, $pos, $len);
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

        // php-src case '\0' when format_len==0 — Missing format specifier (#24661).
        if ($pos >= $len) {
            return [
                'nextPos' => $len,
                'literalPercent' => false,
                'incomplete' => true,
                'spec' => '',
                'positional' => $positional,
                'leftAdjust' => $leftAdjust,
                'padding' => $padding,
                'showSign' => $showSign,
                'width' => $width,
                'widthFromArg' => $widthFromArg,
                'widthPositional' => $widthPositional,
                'precision' => $precision,
                'precisionFromArg' => $precisionFromArg,
                'precisionPositional' => $precisionPositional,
            ];
        }

        return [
            'nextPos' => $pos + 1,
            'literalPercent' => false,
            'incomplete' => false,
            'spec' => $format[$pos],
            'positional' => $positional,
            'leftAdjust' => $leftAdjust,
            'padding' => $padding,
            'showSign' => $showSign,
            'width' => $width,
            'widthFromArg' => $widthFromArg,
            'widthPositional' => $widthPositional,
            'precision' => $precision,
            'precisionFromArg' => $precisionFromArg,
            'precisionPositional' => $precisionPositional,
        ];
    }

    /**
     * php-src php_sprintf_get_argnum — digits+$ → 1-based argnum; else leave position unchanged.
     *
     * @return array{0: ?int, 1: int} [argnum or null, nextPos]
     */
    private static function consumeOptionalArgnum(string $format, int $pos, int $len): array
    {
        if ($pos >= $len || !\ctype_digit($format[$pos])) {
            return [null, $pos];
        }
        $numStart = $pos;
        while ($pos < $len && \ctype_digit($format[$pos])) {
            ++$pos;
        }
        if ($pos >= $len || '$' !== $format[$pos]) {
            return [null, $numStart];
        }
        $argnum = (int) \substr($format, $numStart, $pos - $numStart);
        if ($argnum <= 0) {
            throw new \ValueError(
                'Argument number specifier must be greater than zero and less than 2147483647'
            );
        }

        return [$argnum, $pos + 1];
    }

    /**
     * Resolve a value/width/precision arg index, or record a deferred missing slot (php-src).
     *
     * @param-out int $maxMissingArgnum 0-based highest missing arg index (-1 if none)
     */
    private static function resolveArgIndexOrMissing(
        ?int $positional,
        int &$sequentialIdx,
        int $argCount,
        int &$maxMissingArgnum
    ): ?int {
        if (null !== $positional) {
            if ($positional > $argCount) {
                $maxMissingArgnum = \max($maxMissingArgnum, $positional - 1);

                return null;
            }

            return $positional - 1;
        }
        $idx = $sequentialIdx++;
        if ($idx >= $argCount) {
            $maxMissingArgnum = \max($maxMissingArgnum, $idx);

            return null;
        }

        return $idx;
    }

    private static function throwTooFewArgs(
        bool $arrayArgs,
        int $requiredValueArgs,
        int $givenValueArgs,
        int $nbAdditionalParameters = 1
    ): void {
        if ($arrayArgs) {
            throw new \ValueError(\sprintf(
                'The arguments array must contain %d items, %d given',
                $requiredValueArgs,
                $givenValueArgs
            ));
        }

        // php-src: required = max_missing_argnum + nb_additional_parameters + 1
        throw new \ArgumentCountError(\sprintf(
            '%d arguments are required, %d given',
            $requiredValueArgs + $nbAdditionalParameters,
            $givenValueArgs + $nbAdditionalParameters
        ));
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
        string $padding,
        string $spec
    ): string {
        if (null === $width || $width <= VmString::byteLength($value)) {
            return $value;
        }
        $padLen = $width - VmString::byteLength($value);
        if ($leftAdjust) {
            return $value.str_repeat($padding, $padLen);
        }
        if ('0' === $padding && 's' !== $spec) {
            if (
                str_starts_with($value, '-')
                || str_starts_with($value, '+')
            ) {
                return $value[0].str_repeat('0', $padLen).substr($value, 1);
            }

            return str_repeat('0', $padLen).$value;
        }

        return str_repeat($padding, $padLen).$value;
    }

    private static function applyConversion(
        string $spec,
        Variable $var,
        ?Frame $frame,
        ?int $precision,
        ?string $showSign = null
    ): string {
        $floatPrec = $precision ?? 6;
        switch ($spec) {
            case 's':
                // php-src formatted_print.c — precision truncates %s to N bytes (#21956).
                $string = self::argToString($var, $frame);
                if (null !== $precision && $precision < VmString::byteLength($string)) {
                    return \substr($string, 0, $precision);
                }

                return $string;
            case 'd':
                return self::formatSignedDecimal(self::argToInt($var, $frame), $showSign);
            case 'f':
            case 'F':
                return self::formatFixed(self::argToFloat($var, $frame), $floatPrec, $showSign);
            case 'b':
                return self::formatRadix(self::argToInt($var, $frame), 2, false);
            case 'x':
                return self::formatRadix(self::argToInt($var, $frame), 16, false);
            case 'X':
                return self::formatRadix(self::argToInt($var, $frame), 16, true);
            case 'o':
                return self::formatRadix(self::argToInt($var, $frame), 8, false);
            case 'u':
                return self::intToUnsignedDecimal(self::argToInt($var, $frame));
            case 'c':
                return self::intToChar(self::argToInt($var, $frame));
            case 'e':
                return self::formatScientific(self::argToFloat($var, $frame), false, $floatPrec, $showSign);
            case 'E':
                return self::formatScientific(self::argToFloat($var, $frame), true, $floatPrec, $showSign);
            case 'g':
                return self::formatGeneral(self::argToFloat($var, $frame), false, $floatPrec, $showSign);
            case 'G':
                return self::formatGeneral(self::argToFloat($var, $frame), true, $floatPrec, $showSign);
            case 'h':
                return self::formatGeneralFixed(self::argToFloat($var, $frame), false, $floatPrec, $showSign);
            case 'H':
                return self::formatGeneralFixed(self::argToFloat($var, $frame), true, $floatPrec, $showSign);
            default:
                // php-src formatted_print.c — unknown conversion → ValueError (#27826, #29085).
                // %a/%A are not PHP sprintf conversions (C99-only; retract #9059).
                throw new \ValueError('Unknown format specifier "'.$spec.'"');
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
                // php-src formatted_print.c — %s on float uses zval string conversion, not %f (#23545).
                return VmZendDoubleString::format($var->toFloat());
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? '1' : '';
            case Variable::TYPE_NULL:
                return '';
            case Variable::TYPE_OBJECT:
                return VmString::coerceOperand($var);
            case Variable::TYPE_ARRAY:
                self::warnArrayToString($frame);

                return 'Array';
            default:
                throw new \LogicException('sprintf() %s requires a scalar value in this compiler build');
        }
    }

    private static function warnArrayToString(?Frame $frame): void
    {
        if (null !== $frame?->vmContext) {
            $frame->vmContext->errors->languageWarning(
                'Array to string conversion',
                null,
                0,
                $frame->vmContext,
                $frame
            );

            return;
        }
        @\trigger_error('Array to string conversion', \E_WARNING);
    }

    private static function argToInt(Variable $var, ?Frame $frame = null): int
    {
        $enumInt = null !== $frame
            ? VmScalarType::tryEnumCaseToInt($frame, $var)
            : EnumCaseSupport::tryCastToInt($var);
        if (null !== $enumInt) {
            return $enumInt;
        }

        return VmScalarType::zendIntvalOperand($var, $frame);
    }

    private static function argToFloat(Variable $var, ?Frame $frame = null): float
    {
        $enumFloat = null !== $frame
            ? VmScalarType::tryEnumCaseToFloat($frame, $var)
            : EnumCaseSupport::tryCastToFloat($var);
        if (null !== $enumFloat) {
            return $enumFloat;
        }

        return VmScalarType::zendFloatvalOperand($var, $frame);
    }

    private static function intToDecimal(int $value): string
    {
        if ($value < 0) {
            throw new \LogicException('intToDecimal() expects a non-negative value');
        }
        if (0 === $value) {
            return '0';
        }
        $dec = '0';
        foreach (str_split(dechex($value)) as $hexDigit) {
            $dec = self::decimalMulAdd($dec, 16, (int) \hexdec($hexDigit));
        }

        return $dec;
    }

    /** php-src sprintf.c — SIGN flag for %d. */
    private static function formatSignedDecimal(int $value, ?string $showSign): string
    {
        if ($value < 0) {
            if (PHP_INT_MIN === $value) {
                return '-'.self::intToUnsignedDecimal(PHP_INT_MIN);
            }

            return '-'.self::intToDecimal(-$value);
        }
        $digits = self::intToDecimal($value);
        if ('+' === $showSign) {
            return '+'.$digits;
        }

        return $digits;
    }

    /**
     * @param 2|8|16 $base
     */
    private static function formatRadix(int $value, int $base, bool $upper): string
    {
        return self::intToRadix($value, $base, $upper);
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

    /** php-src sprintf.c — %f (default precision 6; issue #10151, #10796, #11779). */
    private static function formatFixed(float $value, int $precision = 6, ?string $showSign = null): string
    {
        if (\is_nan($value)) {
            return 'NaN';
        }
        if (\is_infinite($value)) {
            // php-src formatted_print.c (PHP 8.2): appends "INF" with is_negative; space/+
            // padding does not emit a sign — -INF and +INF both print as INF (#23607).
            return self::formatInfinity(true);
        }

        return self::applyFloatSignPrefix($value, VmFloatDtoa::formatSprintfF($value, $precision), $showSign);
    }

    /** php-src sprintf.c — %e / %E (default precision 6; half-even via VmFloatDtoa, #29008). */
    private static function formatScientific(float $value, bool $upper, int $precision = 6, ?string $showSign = null): string
    {
        if (\is_nan($value)) {
            return 'NaN';
        }
        if (\is_infinite($value)) {
            return self::formatInfinity(true);
        }

        // Sign for finite values: dtoa body is unsigned; apply +/- / space like %f.
        $body = VmFloatDtoa::formatSprintfE($value, $precision, $upper);
        if ('' !== $body && '-' === $body[0]) {
            return $body;
        }

        return self::positiveFloatSignPrefix($value, $showSign).$body;
    }

    /**
     * php-src formatted_print.c — %g / %G via zend_gcvt (significant digits; #24016).
     */
    private static function formatGeneral(float $value, bool $upper, int $precision = 6, ?string $showSign = null): string
    {
        if (\is_nan($value)) {
            return 'NaN';
        }
        if (\is_infinite($value)) {
            return self::formatInfinity(true);
        }
        $abs = \abs($value);
        if (0.0 === $abs) {
            if (Ieee754::isNegativeZero($value)) {
                return '-0';
            }

            return self::positiveFloatSignPrefix($value, $showSign).'0';
        }

        return self::applyFloatSignPrefix(
            $value,
            VmFloatDtoa::formatSprintfG($value, $precision, $upper),
            $showSign
        );
    }

    /**
     * php-src formatted_print.c — %h / %H (zend_gcvt with non-locale '.'; PHP 8.0+).
     */
    private static function formatGeneralFixed(
        float $value,
        bool $upper,
        int $precision = 6,
        ?string $showSign = null
    ): string {
        if (\is_nan($value)) {
            return 'NaN';
        }
        if (\is_infinite($value)) {
            return self::formatInfinity(true);
        }
        $abs = \abs($value);
        if (0.0 === $abs) {
            if (Ieee754::isNegativeZero($value)) {
                return '-0';
            }

            return self::positiveFloatSignPrefix($value, $showSign).'0';
        }

        return self::applyFloatSignPrefix(
            $value,
            VmFloatDtoa::formatSprintfG($value, $precision, $upper),
            $showSign
        );
    }

    /**
     * php-src formatted_print.c — INF token for float conversions (#23607).
     *
     * PHP 8.2 passes "INF" into php_sprintf_appendstring with is_negative set; with
     * default space padding the leading sign is never emitted, so -INF prints as INF.
     */
    private static function formatInfinity(bool $upper): string
    {
        return $upper ? 'INF' : 'inf';
    }

    /** php-src ext/standard/sprintf.c — SIGN flag on float conversions (#11779). */
    private static function positiveFloatSignPrefix(float $value, ?string $showSign): string
    {
        if ($value < 0.0) {
            return '';
        }
        if ('+' === $showSign) {
            return '+';
        }
        if (' ' === $showSign) {
            return ' ';
        }

        return '';
    }

    private static function applyFloatSignPrefix(float $value, string $formatted, ?string $showSign): string
    {
        if ($value < 0.0 || str_starts_with($formatted, '-') || str_starts_with($formatted, '+')) {
            return $formatted;
        }
        $prefix = self::positiveFloatSignPrefix($value, $showSign);

        return '' === $prefix ? $formatted : $prefix.$formatted;
    }
}
