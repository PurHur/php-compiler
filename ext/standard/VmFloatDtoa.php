<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP double → decimal formatting (php-src Zend/zend_strtod.c zend_gcvt mode 0 / %.*H).
 *
 * Used by var_dump(), serialize() dtoa mode, and other stdlib float export paths (#5412, #7103).
 */
final class VmFloatDtoa
{
    private const NDIGIT = 17;

    /** php-src ext/standard/var.c — %.*H with PG(serialize_precision) == -1. */
    public static function formatH(float $value): string
    {
        if (\is_nan($value)) {
            return 'NAN';
        }
        if (\is_infinite($value)) {
            return $value > 0.0 ? 'INF' : '-INF';
        }
        if (0.0 === $value) {
            return '0';
        }

        $abs = \abs($value);
        if ($abs >= 1e14) {
            return self::formatLargeScientific($value);
        }

        return VmSerializeFormat::formatDoubleWithPrecision($value, -1);
    }

    /** var_dump()/debug_zval_dump() float branch (ext/standard/var.c php_var_dump). */
    public static function formatVarDump(float $value): string
    {
        return self::formatH($value);
    }

    private static function formatLargeScientific(float $value): string
    {
        $negative = $value < 0.0;
        $abs = \abs($value);
        $scaled = self::formatLargeScientificScaled($value);
        $scaledBody = $negative ? \substr($scaled, 1) : $scaled;

        foreach ([16, 17] as $precision) {
            $candidate = VmSerializeFormat::formatDoubleWithPrecision($abs, $precision);
            if (!self::isScientificNotation($candidate)) {
                continue;
            }
            if ($abs === (float) $candidate && \strlen($candidate) < \strlen($scaledBody)) {
                return $negative ? '-'.$candidate : $candidate;
            }
        }

        return $scaled;
    }

    private static function formatLargeScientificScaled(float $value): string
    {
        $negative = $value < 0.0;
        [$exp, $frac] = self::floatToParts($value);

        if (0 === $exp) {
            if (0 === $frac) {
                return '0';
            }
            $sig = $frac;
            $binExp = -1022 - 52;
        } else {
            $sig = (1 << 52) | $frac;
            $binExp = $exp - 1023 - 52;
        }

        [$digits, $decpt] = self::sigExpToDigits($sig, $binExp);
        $formatted = self::gcvt($digits, $decpt, self::NDIGIT);

        return $negative ? '-'.$formatted : $formatted;
    }

    private static function isScientificNotation(string $formatted): bool
    {
        return \str_contains($formatted, 'E') || \str_contains($formatted, 'e');
    }

    /** @return array{0: int, 1: int} exponent and 52-bit fraction (php-src IEEE754 double). */
    private static function floatToParts(float $value): array
    {
        $bytes = \pack('d', $value);
        $bits = self::unpackDoubleBits($bytes);
        $exp = (int) (($bits >> 52) & 0x7FF);
        $frac = (int) ($bits & 0x000FFFFFFFFFFFFF);

        return [$exp, $frac];
    }

    private static function unpackDoubleBits(string $bytes): int
    {
        static $littleEndian = null;
        if (null === $littleEndian) {
            $littleEndian = \pack('S', 1) === \pack('v', 1);
        }

        return $littleEndian ? \unpack('P', $bytes)[1] : \unpack('J', $bytes)[1];
    }

    /**
     * @return array{0: string, 1: int} digit run without decimal point; decpt is index of units digit
     */
    private static function sigExpToDigits(int $sig, int $binExp): array
    {
        $scale = self::NDIGIT + 8;
        $digits = self::intToDecimal($sig);
        for ($i = 0; $i < $scale; ++$i) {
            $digits = self::decimalMulSmall($digits, 10);
        }
        if ($binExp >= 0) {
            for ($i = 0; $i < $binExp; ++$i) {
                $digits = self::decimalMulSmall($digits, 2);
            }
        } else {
            for ($i = 0; $i < -$binExp; ++$i) {
                [$digits] = self::binaryDiv2($digits, 0);
            }
        }

        $digits = self::trimLeadingZeros($digits);
        if ('0' === $digits) {
            return ['0', 0];
        }

        return [$digits, \strlen($digits) - $scale];
    }

    /** php-src zend_gcvt() — E-style when decpt > ndigit or decpt < -3. */
    private static function gcvt(string $digits, int $decpt, int $ndigit): string
    {
        $digits = self::trimLeadingZeros($digits);
        if ('' === $digits) {
            return '0';
        }

        if (($decpt >= 0 && $decpt > $ndigit) || $decpt < -3) {
            return self::formatScientific($digits, $decpt, $ndigit);
        }

        if ($decpt <= 0) {
            $frac = '0.'.\str_repeat('0', -$decpt).$digits;
            $frac = self::trimTrailingZeros($frac);

            return \rtrim($frac, '.');
        }

        if ($decpt >= \strlen($digits)) {
            return $digits.\str_repeat('0', $decpt - \strlen($digits));
        }

        $whole = \substr($digits, 0, $decpt);
        $frac = \substr($digits, $decpt);
        $frac = self::trimTrailingZeros($frac);
        if ('' === $frac) {
            return $whole;
        }

        return $whole.'.'.$frac;
    }

    private static function formatScientific(string $digits, int $decpt, int $ndigit): string
    {
        $digits = self::trimLeadingZeros($digits);
        if ('' === $digits) {
            return '0';
        }

        $exp = $decpt - 1;
        if ($exp >= \strlen($digits)) {
            $exp = \strlen($digits) - 1;
        }
        if ($exp < 0) {
            $exp = 0;
        }

        $mantDigits = $digits;
        if ($decpt > 0 && $decpt <= \strlen($digits)) {
            $mantDigits = \substr($digits, 0, $decpt).\substr($digits, $decpt);
        } elseif ($decpt <= 0) {
            $mantDigits = $digits;
            $exp = -1;
        }

        $mantDigits = self::trimLeadingZeros($mantDigits);
        if ('' === $mantDigits) {
            $mantDigits = '0';
        }

        $sigLen = \min($ndigit, \strlen($mantDigits));
        $mant = \substr($mantDigits, 0, $sigLen);
        if (\strlen($mantDigits) > $sigLen) {
            $next = (int) $mantDigits[$sigLen];
            if ($next >= 5) {
                $mant = self::roundUpDigits($mant);
                if (\strlen($mant) > $sigLen) {
                    $mant = \substr($mant, 0, $sigLen);
                    ++$exp;
                }
            }
        }

        if (\strlen($mant) > 1) {
            $body = $mant[0].'.'.\substr($mant, 1);
            $body = self::trimTrailingZeros($body);
            if (\str_ends_with($body, '.')) {
                $body = \rtrim($body, '.');
            }
        } else {
            $body = $mant;
        }

        $expSign = $exp >= 0 ? '+' : '-';
        $expAbs = \abs($exp);

        return $body.'E'.$expSign.$expAbs;
    }

    private static function roundUpDigits(string $digits): string
    {
        $carry = 1;
        $out = '';
        for ($i = \strlen($digits) - 1; $i >= 0; --$i) {
            $d = (int) $digits[$i] + $carry;
            $out = (string) ($d % 10).$out;
            $carry = intdiv($d, 10);
        }
        if ($carry > 0) {
            $out = (string) $carry.$out;
        }

        return $out;
    }

    private static function intToDecimal(int $n): string
    {
        if ($n <= 0) {
            return '0';
        }
        $hex = \dechex($n);
        $dec = '0';
        foreach (\str_split($hex) as $hexDigit) {
            $dec = self::decimalMulAdd($dec, 16, (int) \hexdec($hexDigit));
        }

        return $dec;
    }

    /** @return array{0: string, 1: int} quotient and binary remainder */
    private static function binaryDiv2(string $digits, int $remainder): array
    {
        $out = '';
        $carry = $remainder;
        for ($i = 0; $i < \strlen($digits); ++$i) {
            $value = $carry * 10 + (int) $digits[$i];
            $out .= (string) intdiv($value, 2);
            $carry = $value % 2;
        }
        $out = self::trimLeadingZeros($out);

        return ['' === $out ? '0' : $out, $carry];
    }

    private static function decimalMulSmall(string $decimal, int $multiplier): string
    {
        $carry = 0;
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

    private static function trimLeadingZeros(string $digits): string
    {
        $digits = \ltrim($digits, '0');

        return '' === $digits ? '0' : $digits;
    }

    private static function trimTrailingZeros(string $digits): string
    {
        if (!\str_contains($digits, '.')) {
            return $digits;
        }
        $digits = \rtrim($digits, '0');

        return \rtrim($digits, '.');
    }
}
