<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Native serialize() scalar/array encoding with compiler-owned serialize_precision (#7103).
 *
 * php-src: ext/standard/var.c — php_var_serialize_intern double branch, smart_str_append_double
 */
final class VmSerializeFormat
{
    /**
     * Encode exported PHP data (VmJson::export shape) without host serialize()/ini_get().
     *
     * @param array<mixed>|bool|float|int|null|string $exported
     */
    public static function encodeExported(mixed $exported): string
    {
        if ($exported instanceof VmSerializeEnumCaseRef) {
            return VmSerialize::encodeEnumCaseLiteral($exported->className, $exported->caseName);
        }
        if (null === $exported) {
            return 'N;';
        }
        if (is_bool($exported)) {
            return $exported ? 'b:1;' : 'b:0;';
        }
        if (is_int($exported)) {
            return 'i:'.$exported.';';
        }
        if (is_float($exported)) {
            return 'd:'.self::formatDouble($exported).';';
        }
        if (is_string($exported)) {
            return self::encodeString($exported);
        }
        if (is_array($exported)) {
            return self::encodeArray($exported);
        }

        throw new \LogicException('serialize() unsupported exported type in this compiler build');
    }

    /** Format double using VmIni serialize_precision (php-src PG(serialize_precision)). */
    public static function formatDouble(float $num): string
    {
        $precision = VmIni::parseSerializePrecision(VmIni::getSerializePrecision());

        return self::formatDoubleWithPrecision($num, $precision);
    }

    public static function formatDoubleWithPrecision(float $num, int $precision): string
    {
        if (is_nan($num)) {
            return 'NAN';
        }
        if (is_infinite($num)) {
            return $num > 0.0 ? 'INF' : '-INF';
        }
        if (0.0 === $num) {
            return Ieee754::isNegativeZero($num) ? '-0' : '0';
        }
        if ($precision < 0) {
            return self::formatDtoa($num);
        }

        return self::formatSigDigits($num, $precision);
    }

    public static function encodeStringLiteral(string $value): string
    {
        return self::encodeString($value);
    }

    private static function encodeString(string $value): string
    {
        $len = \strlen($value);

        return 's:'.$len.':"'.$value.'";';
    }

    /**
     * @param array<mixed> $array
     */
    private static function encodeArray(array $array): string
    {
        $body = '';
        foreach ($array as $key => $value) {
            if (is_int($key)) {
                $body .= 'i:'.$key.';';
            } elseif (is_string($key)) {
                $body .= self::encodeString($key);
            } else {
                throw new \LogicException('serialize() array keys must be int or string');
            }
            $body .= self::encodeExported($value);
        }

        return 'a:'.\count($array).':{'.$body.'}';
    }

    /** php-src %.*G (uppercase E) for serialize_precision >= 0. */
    private static function formatSigDigits(float $num, int $precision): string
    {
        if ($precision <= 0) {
            return '0';
        }

        $negative = $num < 0.0 || Ieee754::isNegativeZero($num);
        $num = abs($num);
        if (0.0 === $num) {
            return ($negative ? '-' : '').'0';
        }

        $exp = (int) floor(\log10($num));
        $useScientific = $exp < -4 || $exp >= $precision;

        if ($useScientific) {
            $mantissa = $num / (10 ** $exp);
            $decimals = max(0, $precision - 1);
            $rounded = self::roundToDecimals($mantissa, $decimals);
            if ($rounded >= 10.0) {
                $rounded = 1.0;
                ++$exp;
            }
            $digits = self::trimTrailingZeros(VmNumberFormat::format($rounded, $decimals, '.', ''));

            return ($negative ? '-' : '').$digits.'E'.($exp >= 0 ? '+' : '').$exp;
        }

        $decimals = max(0, $precision - 1 - $exp);
        $rounded = self::roundToDecimals($num, $decimals);
        $digits = self::trimTrailingZeros(VmNumberFormat::format($rounded, $decimals, '.', ''));

        return ($negative ? '-' : '').$digits;
    }

    /** php-src %.*H with precision -1 (dtoa mode 0). */
    private static function formatDtoa(float $num): string
    {
        if (abs($num) >= 1e14) {
            return VmFloatDtoa::formatH($num);
        }

        $negative = $num < 0.0 || Ieee754::isNegativeZero($num);
        $num = abs($num);

        for ($digits = 1; $digits <= 17; ++$digits) {
            $candidate = self::formatSigDigits($num, $digits);
            if (self::floatRepEquals($num, $candidate)) {
                return ($negative ? '-' : '').$candidate;
            }
        }

        $fallback = self::trimTrailingZeros(VmNumberFormat::format($num, 16, '.', ''));
        if ('' === $fallback || '0' === $fallback) {
            $fallback = self::formatSigDigits($num, 17);
        }

        return ($negative ? '-' : '').$fallback;
    }

    private static function floatRepEquals(float $num, string $candidate): bool
    {
        if (!is_numeric($candidate)) {
            return false;
        }

        return $num === (float) $candidate;
    }

    private static function roundToDecimals(float $num, int $decimals): float
    {
        if ($decimals <= 0) {
            return round($num, 0);
        }
        $pow = 10 ** $decimals;

        return round($num * $pow) / $pow;
    }

    private static function trimTrailingZeros(string $digits): string
    {
        if (!str_contains($digits, '.')) {
            return $digits;
        }
        $digits = rtrim($digits, '0');

        return rtrim($digits, '.');
    }
}
