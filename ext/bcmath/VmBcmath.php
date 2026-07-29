<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\ext\standard\StdlibConstants;

/**
 * Arbitrary-precision decimal string math (php-src ext/bcmath/libbcmath; issue #3365).
 */
final class VmBcmath
{
    private static int $defaultScale = 0;

    public static function scale(?int $scale = null): int
    {
        $old = self::$defaultScale;
        if (1 === \func_num_args() && null !== $scale) {
            if ($scale < 0) {
                throw new \ValueError('bcscale(): Argument #1 ($scale) must be greater than or equal to 0');
            }
            self::$defaultScale = $scale;
        }

        return $old;
    }

    public static function add(string $left, string $right, ?int $scale = null, ?int $roundingMode = null): string
    {
        $result = self::addNumbers(self::parse($left), self::parse($right));

        return self::formatBinaryResult($result, $scale, $roundingMode);
    }

    public static function sub(string $left, string $right, ?int $scale = null, ?int $roundingMode = null): string
    {
        $right = self::parse($right);
        $right['sign'] *= -1;

        return self::formatBinaryResult(self::addNumbers(self::parse($left), $right), $scale, $roundingMode);
    }

    public static function mul(string $left, string $right, ?int $scale = null, ?int $roundingMode = null): string
    {
        $a = self::parse($left);
        $b = self::parse($right);
        $product = self::mulDigitStrings(self::unscaledDigits($a), self::unscaledDigits($b));
        $scaleSum = \strlen($a['frac']) + \strlen($b['frac']);
        $result = self::fromUnscaled($product, $scaleSum, $a['sign'] * $b['sign']);

        return self::formatBinaryResult($result, $scale, $roundingMode);
    }

    public static function div(string $left, string $right, ?int $scale = null, ?int $roundingMode = null): string
    {
        $scale = self::resolveScale($scale);
        $a = self::parse($left);
        $b = self::parse($right);
        if (self::isZero($b)) {
            throw new \DivisionByZeroError('Division by zero');
        }
        $quotient = self::divDigitStrings(
            self::unscaledDigits($a),
            \strlen($a['frac']),
            self::unscaledDigits($b),
            \strlen($b['frac']),
            $scale
        );

        return self::formatBinaryResult($quotient, $scale, $roundingMode);
    }

    /**
     * Quotient + remainder pair (php-src ext/bcmath/bcmath.c PHP_FUNCTION(bcdivmod), #6966).
     *
     * @return array{0: string, 1: string}
     */
    public static function divmod(string $left, string $right, ?int $scale = null, ?int $roundingMode = null): array
    {
        $scale = self::resolveScale($scale);
        $b = self::parse($right);
        if (self::isZero($b)) {
            throw new \DivisionByZeroError('Division by zero');
        }
        $quotient = self::div($left, $right, 0, $roundingMode);
        $product = self::mul($quotient, $right, $scale);
        $remainder = self::sub($left, $product, $scale);

        return [$quotient, $remainder];
    }

    /** Ceiling toward +infinity (php-src ext/bcmath/bcmath.c PHP_FUNCTION(bcceil); issue #6026). */
    public static function ceil(string $num): string
    {
        $parsed = self::parse($num);
        if (!self::hasFractionalValue($parsed)) {
            return self::formatIntegerMagnitude($parsed);
        }
        if ($parsed['sign'] > 0) {
            return self::format(['sign' => 1, 'int' => self::addDigitStrings($parsed['int'], '1'), 'frac' => ''], 0);
        }

        return self::formatIntegerMagnitude($parsed);
    }

    /** Floor toward -infinity (php-src ext/bcmath/bcmath.c PHP_FUNCTION(bcfloor); issue #6026). */
    public static function floor(string $num): string
    {
        $parsed = self::parse($num);
        if (!self::hasFractionalValue($parsed)) {
            return self::formatIntegerMagnitude($parsed);
        }
        if ($parsed['sign'] > 0) {
            return self::formatIntegerMagnitude($parsed);
        }

        return self::format([
            'sign' => -1,
            'int' => self::addDigitStrings($parsed['int'], '1'),
            'frac' => '',
        ], 0);
    }

    /**
     * Round to precision with RoundingMode (php-src ext/bcmath/libbcmath/src/round.c; issue #5935).
     */
    public static function round(
        string $num,
        int $precision = 0,
        int $mode = StdlibConstants::PHP_ROUND_HALF_UP
    ): string {
        if ($precision < \PHP_INT_MIN || $precision > \PHP_INT_MAX) {
            throw new \ValueError(\sprintf(
                'bcround(): Argument #2 ($precision) must be between %d and %d',
                \PHP_INT_MIN,
                \PHP_INT_MAX
            ));
        }

        try {
            $parsed = self::parse($num);
        } catch (\ValueError) {
            throw new \ValueError('bcround(): Argument #1 ($num) is not well-formed');
        }

        if ($precision < 0 && \strlen($parsed['int']) < (-($precision + 1)) + 1) {
            return self::roundOverscaleNegativePrecision($parsed, $precision, $mode);
        }

        if ($precision >= 0 && \strlen($parsed['frac']) <= $precision) {
            if (\strlen($parsed['frac']) < $precision) {
                $parsed['frac'] = \str_pad($parsed['frac'], $precision, '0', STR_PAD_RIGHT);
            }

            return self::formatRoundOutput($parsed, $precision);
        }

        $nLen = \strlen($parsed['int']);
        $nValue = $parsed['int'].$parsed['frac'];
        $roundedLen = $nLen + $precision;
        $kept = $roundedLen <= 0 ? '' : \substr($nValue, 0, $roundedLen);

        if (self::shouldRoundUpBc($parsed, $roundedLen, $mode)) {
            if ($roundedLen <= 0) {
                $kept = \str_pad('1', $nLen + 1, '0', STR_PAD_RIGHT);
            } else {
                $kept = self::addDigitStrings($kept, '1');
            }
        }

        $resultScale = $precision > 0 ? $precision : 0;
        if ('' === $kept || self::isAllZeroDigits($kept)) {
            return '0';
        }

        return self::formatKeptBcDigits($kept, $parsed['sign'], $resultScale);
    }

    public static function comp(string $left, string $right, ?int $scale = null): int
    {
        $scale = self::resolveScale($scale);
        $a = self::format(self::parse($left), $scale);
        $b = self::format(self::parse($right), $scale);
        $pa = self::parse($a);
        $pb = self::parse($b);
        if ($pa['sign'] !== $pb['sign']) {
            return $pa['sign'] <=> $pb['sign'];
        }
        $mag = self::compareMagnitude($pa, $pb);

        return $pa['sign'] * $mag;
    }

    /** Remainder of arbitrary-precision division (php-src ext/bcmath/bcmath.c PHP_FUNCTION(bcmod); issue #6042). */
    public static function mod(string $left, string $right, ?int $scale = null, ?int $roundingMode = null): string
    {
        $scale = self::resolveScale($scale);
        $b = self::parse($right);
        if (self::isZero($b)) {
            throw new \DivisionByZeroError('Division by zero');
        }
        [, $remainder] = self::divmod($left, $right, $scale, $roundingMode);

        return $remainder;
    }

    /** Exponentiation (php-src ext/bcmath/libbcmath/src/raise.c; issue #6042). */
    public static function pow(string $base, string $exponent, ?int $scale = null): string
    {
        $scale = self::resolveScale($scale);
        $baseParsed = self::parse($base);
        $expoParsed = self::parse($exponent);
        if ($expoParsed['sign'] < 0) {
            throw new \ValueError('bcpow(): Argument #2 ($exponent) must be greater than or equal to 0');
        }
        if (self::isZero($baseParsed) && self::isZero($expoParsed)) {
            throw new \ValueError('bcpow(): 0 raised to the power of 0 is undefined');
        }
        if (self::isZero($expoParsed)) {
            return self::format(['sign' => 1, 'int' => '1', 'frac' => ''], $scale);
        }
        if (self::isZero($baseParsed)) {
            return self::format(['sign' => 1, 'int' => '0', 'frac' => ''], $scale);
        }
        if (self::hasFractionalValue($expoParsed)) {
            if (self::isHalfExponent($expoParsed)) {
                if ($baseParsed['sign'] < 0) {
                    throw new \ValueError('bcpow(): Argument #1 ($num) must be greater than or equal to 0 for fractional exponents');
                }

                return self::sqrt($base, $scale);
            }

            return self::powGeneralFractional($base, $exponent, $scale);
        }

        $internalScale = max($scale + 2, self::$defaultScale + 2);
        $expoDigits = self::unscaledDigits($expoParsed);
        $result = '1';
        $baseStr = $base;
        while (self::cmpDigitStrings($expoDigits, '0') > 0) {
            if (1 === ((int) $expoDigits[\strlen($expoDigits) - 1] % 2)) {
                $result = self::mul($result, $baseStr, $internalScale);
            }
            $baseStr = self::mul($baseStr, $baseStr, $internalScale);
            $expoDigits = self::divDigitStringByTwo($expoDigits);
        }

        return self::format(self::parse($result), $scale);
    }

    /** Square root (php-src ext/bcmath/libbcmath/src/sqrt.c; issue #6042). */
    public static function sqrt(string $num, ?int $scale = null): string
    {
        $scale = self::resolveScale($scale);
        $parsed = self::parse($num);
        if ($parsed['sign'] < 0 && !self::isZero($parsed)) {
            throw new \ValueError('bcsqrt(): Argument #1 ($num) must be greater than or equal to 0');
        }
        if (self::isZero($parsed)) {
            return self::format(['sign' => 1, 'int' => '0', 'frac' => ''], $scale);
        }

        $workScale = max($scale + 4, 8);
        $guess = self::div($num, '2', $workScale);
        if (self::comp($guess, '0', $workScale) <= 0) {
            $guess = $num;
        }
        for ($i = 0; $i < 50; ++$i) {
            $next = self::div(
                self::add($guess, self::div($num, $guess, $workScale), $workScale),
                '2',
                $workScale
            );
            if (0 === self::comp($next, $guess, $workScale)) {
                break;
            }
            $guess = $next;
        }

        return self::format(self::parse($guess), $scale);
    }

    /**
     * Modular exponentiation (php-src ext/bcmath/libbcmath/src/raisemod.c; issue #6976).
     */
    public static function powmod(string $base, string $exponent, string $modulus, ?int $scale = null, ?int $roundingMode = null): string
    {
        $scale = self::resolveScale($scale);
        $baseNum = self::parseInteger($base, 1);
        $expoNum = self::parseInteger($exponent, 2);
        $modNum = self::parseInteger($modulus, 3);

        if (self::isZero($modNum)) {
            throw new \DivisionByZeroError('Modulo by zero');
        }
        if ($expoNum['sign'] < 0) {
            throw new \ValueError('bcpowmod(): Argument #2 ($exponent) must be greater than or equal to 0');
        }

        $baseStr = self::format($baseNum, 0);
        $modStr = self::format($modNum, 0);
        $baseStr = self::modInt($baseStr, $modStr);

        if (self::isZero($expoNum)) {
            return self::format(['sign' => 1, 'int' => '1', 'frac' => ''], $scale);
        }

        $expoDigits = self::unscaledDigits($expoNum);
        $result = '1';
        while (self::cmpDigitStrings($expoDigits, '0') > 0) {
            if (1 === ((int) $expoDigits[\strlen($expoDigits) - 1] % 2)) {
                $result = self::modInt(self::mul($result, $baseStr, 0), $modStr);
            }
            $baseStr = self::modInt(self::mul($baseStr, $baseStr, 0), $modStr);
            $expoDigits = self::divDigitStringByTwo($expoDigits);
        }

        return self::formatBinaryResult(self::parse($result), $scale, $roundingMode);
    }

    private static function resolveScale(?int $scale): int
    {
        return null !== $scale ? $scale : self::$defaultScale;
    }

    /**
     * @param array{sign:int,int:string,frac:string} $result
     */
    private static function formatBinaryResult(array $result, ?int $scale, ?int $roundingMode): string
    {
        if (null !== $roundingMode) {
            return self::round(self::toExactString($result), self::resolveScale($scale), $roundingMode);
        }

        return self::format($result, self::resolveScale($scale));
    }

    /**
     * @param array{sign:int,int:string,frac:string} $num
     */
    private static function toExactString(array $num): string
    {
        if (self::isZero($num)) {
            return '0';
        }
        $sign = $num['sign'] < 0 ? '-' : '';
        $frac = \rtrim($num['frac'], '0');
        if ('' === $frac) {
            return $sign.$num['int'];
        }

        return $sign.$num['int'].'.'.$frac;
    }

    /** Validate arbitrary-precision operand (php-src ext/bcmath/bcmath.c; issue #7220). */
    public static function assertValidNumber(string $num): void
    {
        self::parse($num);
    }

    /**
     * Canonical BcMath\Number::$value string (php-src bc_num stringification; #24140).
     *
     * Strips leading integer zeros, keeps fractional digits (incl. trailing zeros), and
     * maps empty / lone-sign / lone-dot inputs to {@see "0"} after {@see parse()}.
     */
    public static function canonicalNumberString(string $num): string
    {
        $parsed = self::parse($num);
        $sign = $parsed['sign'] < 0 ? '-' : '';
        if ('' === $parsed['frac']) {
            return $sign.$parsed['int'];
        }

        return $sign.$parsed['int'].'.'.$parsed['frac'];
    }

    /** Decimal places after the radix point for a validated operand (#7220). */
    public static function decimalScale(string $num): int
    {
        return \strlen(self::parse($num)['frac']);
    }

    /**
     * Parse a bcmath decimal operand (php-src ext/bcmath php_str2num / libbcmath).
     *
     * Empty string, lone sign, and lone "." are zero (#21006 / #20973 soft-null path:
     * null coerces to "" then becomes 0). Do not trim — whitespace is invalid.
     *
     * @return array{sign:int,int:string,frac:string}
     */
    private static function parse(string $num): array
    {
        // php-src treats "" / "+" / "-" / "." / "+." / "-." as 0 (not ValueError).
        if ('' === $num) {
            return ['sign' => 1, 'int' => '0', 'frac' => ''];
        }

        $sign = 1;
        if ('+' === $num[0] || '-' === $num[0]) {
            $sign = '-' === $num[0] ? -1 : 1;
            $num = \substr($num, 1);
        }

        if ('' === $num || '.' === $num) {
            // Signed zero collapses to +0 like Zend bcround('-', 0) → "0".
            return ['sign' => 1, 'int' => '0', 'frac' => ''];
        }

        if (!\preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)$/', $num)) {
            throw new \ValueError('bcmath function(): Argument is not a valid number');
        }

        if ('.' === $num[0]) {
            $int = '0';
            $frac = \substr($num, 1);
        } elseif (false !== ($dot = \strpos($num, '.'))) {
            $int = \substr($num, 0, $dot);
            $frac = \substr($num, $dot + 1);
        } else {
            $int = $num;
            $frac = '';
        }

        $parsed = [
            'sign' => $sign,
            'int' => self::stripLeadingZeros($int),
            'frac' => $frac,
        ];
        // Normalize -0 / -0.0 to +0 (php-src bcround('-0', 0) → "0").
        if (self::isZero($parsed)) {
            $parsed['sign'] = 1;
        }

        return $parsed;
    }

    /**
     * @param array{sign:int,int:string,frac:string} $num
     */
    private static function unscaledDigits(array $num): string
    {
        $digits = self::stripLeadingZeros($num['int'].$num['frac']);

        return '0' === $digits ? '0' : $digits;
    }

    /**
     * @return array{sign:int,int:string,frac:string}
     */
    private static function fromUnscaled(string $digits, int $scale, int $sign): array
    {
        $digits = self::stripLeadingZeros($digits);
        if ('0' === $digits) {
            return ['sign' => 1, 'int' => '0', 'frac' => ''];
        }
        if ($scale <= 0) {
            return ['sign' => $sign >= 0 ? 1 : -1, 'int' => $digits, 'frac' => ''];
        }
        if (\strlen($digits) <= $scale) {
            $digits = \str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        }
        $split = \strlen($digits) - $scale;

        return [
            'sign' => $sign >= 0 ? 1 : -1,
            'int' => self::stripLeadingZeros(\substr($digits, 0, $split)),
            'frac' => \substr($digits, $split),
        ];
    }

    /**
     * @param array{sign:int,int:string,frac:string} $a
     * @param array{sign:int,int:string,frac:string} $b
     *
     * @return array{sign:int,int:string,frac:string}
     */
    private static function addNumbers(array $a, array $b): array
    {
        if ($a['sign'] === $b['sign']) {
            $maxFrac = \max(\strlen($a['frac']), \strlen($b['frac']));
            $aDigits = self::unscaledDigits([
                'sign' => 1,
                'int' => $a['int'],
                'frac' => \str_pad($a['frac'], $maxFrac, '0', STR_PAD_RIGHT),
            ]);
            $bDigits = self::unscaledDigits([
                'sign' => 1,
                'int' => $b['int'],
                'frac' => \str_pad($b['frac'], $maxFrac, '0', STR_PAD_RIGHT),
            ]);
            $sum = self::addDigitStrings($aDigits, $bDigits);

            return self::fromUnscaled($sum, $maxFrac, $a['sign']);
        }

        $cmp = self::compareMagnitude($a, $b);
        if (0 === $cmp) {
            return ['sign' => 1, 'int' => '0', 'frac' => ''];
        }
        if ($cmp < 0) {
            $tmp = $a;
            $a = $b;
            $b = $tmp;
        }

        $maxFrac = \max(\strlen($a['frac']), \strlen($b['frac']));
        $aDigits = self::unscaledDigits([
            'sign' => 1,
            'int' => $a['int'],
            'frac' => \str_pad($a['frac'], $maxFrac, '0', STR_PAD_RIGHT),
        ]);
        $bDigits = self::unscaledDigits([
            'sign' => 1,
            'int' => $b['int'],
            'frac' => \str_pad($b['frac'], $maxFrac, '0', STR_PAD_RIGHT),
        ]);
        $diff = self::subDigitStrings($aDigits, $bDigits);

        return self::fromUnscaled($diff, $maxFrac, $a['sign']);
    }

    /**
     * @param array{sign:int,int:string,frac:string} $num
     */
    private static function format(array $num, int $scale): string
    {
        if ($scale < 0) {
            throw new \ValueError('bcmath function(): scale must be greater than or equal to 0');
        }

        $sign = $num['sign'] < 0 ? '-' : '';
        $fracLen = \strlen($num['frac']);
        $digits = self::unscaledDigits($num);
        if ($fracLen < $scale + 1) {
            $digits = \str_pad($digits, \strlen($digits) + ($scale + 1 - $fracLen), '0', STR_PAD_RIGHT);
            $fracLen = $scale + 1;
        }

        $intPart = \substr($digits, 0, -$fracLen);
        $fracPart = \substr($digits, -$fracLen);
        $roundDigit = (int) $fracPart[$scale];
        $fracPart = \substr($fracPart, 0, $scale);
        if ($roundDigit >= 5) {
            $incremented = self::addDigitStrings($intPart.$fracPart, '1');
            if (\strlen($incremented) > \strlen($intPart) + $scale) {
                $intPart = \substr($incremented, 0, -$scale);
                $fracPart = \substr($incremented, -$scale);
            } else {
                $intPart = \substr($incremented, 0, \max(1, \strlen($incremented) - $scale));
                $fracPart = \str_pad(\substr($incremented, -\min($scale, \strlen($incremented))), $scale, '0', STR_PAD_LEFT);
            }
        }
        $intPart = self::stripLeadingZeros($intPart);
        $fracPart = \str_pad($fracPart, $scale, '0', STR_PAD_RIGHT);

        if (0 === $scale) {
            return $sign.$intPart;
        }

        return $sign.$intPart.'.'.$fracPart;
    }

    /**
     * @param array{sign:int,int:string,frac:string} $a
     * @param array{sign:int,int:string,frac:string} $b
     */
    private static function compareMagnitude(array $a, array $b): int
    {
        $aDigits = self::unscaledDigits($a);
        $bDigits = self::unscaledDigits($b);
        $aScale = \strlen($a['frac']);
        $bScale = \strlen($b['frac']);
        if ($aScale !== $bScale) {
            $maxScale = \max($aScale, $bScale);
            $aDigits = self::unscaledDigits([
                'sign' => 1,
                'int' => $a['int'],
                'frac' => \str_pad($a['frac'], $maxScale, '0', STR_PAD_RIGHT),
            ]);
            $bDigits = self::unscaledDigits([
                'sign' => 1,
                'int' => $b['int'],
                'frac' => \str_pad($b['frac'], $maxScale, '0', STR_PAD_RIGHT),
            ]);
        }
        if (\strlen($aDigits) !== \strlen($bDigits)) {
            return \strlen($aDigits) <=> \strlen($bDigits);
        }

        return \strcmp($aDigits, $bDigits) <=> 0;
    }

    /**
     * @param array{sign:int,int:string,frac:string} $num
     */
    private static function isZero(array $num): bool
    {
        return '0' === self::unscaledDigits($num);
    }

    private static function stripLeadingZeros(string $digits): string
    {
        $digits = \ltrim($digits, '0');

        return '' === $digits ? '0' : $digits;
    }

    private static function addDigitStrings(string $a, string $b): string
    {
        $a = \strrev($a);
        $b = \strrev($b);
        $len = \max(\strlen($a), \strlen($b));
        $carry = 0;
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $sum = ($i < \strlen($a) ? (int) $a[$i] : 0) + ($i < \strlen($b) ? (int) $b[$i] : 0) + $carry;
            $out .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }
        if ($carry > 0) {
            $out .= (string) $carry;
        }

        return self::stripLeadingZeros(\strrev($out));
    }

    private static function subDigitStrings(string $a, string $b): string
    {
        $a = \strrev($a);
        $b = \strrev($b);
        $borrow = 0;
        $out = '';
        for ($i = 0; $i < \strlen($a); ++$i) {
            $da = (int) $a[$i] - $borrow;
            $db = $i < \strlen($b) ? (int) $b[$i] : 0;
            if ($da < $db) {
                $da += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $out .= (string) ($da - $db);
        }

        return self::stripLeadingZeros(\strrev($out));
    }

    private static function mulDigitStrings(string $a, string $b): string
    {
        if ('0' === $a || '0' === $b) {
            return '0';
        }
        $result = '0';
        $bRev = \strrev($b);
        for ($i = 0; $i < \strlen($bRev); ++$i) {
            $partial = self::mulSingleDigit($a, (int) $bRev[$i]);
            if ($i > 0) {
                $partial .= \str_repeat('0', $i);
            }
            $result = self::addDigitStrings($result, $partial);
        }

        return $result;
    }

    private static function mulSingleDigit(string $a, int $digit): string
    {
        if (0 === $digit) {
            return '0';
        }
        $a = \strrev($a);
        $carry = 0;
        $out = '';
        for ($i = 0; $i < \strlen($a); ++$i) {
            $prod = (int) $a[$i] * $digit + $carry;
            $out .= (string) ($prod % 10);
            $carry = intdiv($prod, 10);
        }
        if ($carry > 0) {
            $out .= (string) $carry;
        }

        return \strrev($out);
    }

    /**
     * @return array{sign:int,int:string,frac:string}
     */
    private static function divDigitStrings(string $aDigits, int $aScale, string $bDigits, int $bScale, int $outScale): array
    {
        $aDigits = self::stripLeadingZeros($aDigits);
        $bDigits = self::stripLeadingZeros($bDigits);
        $sign = 1;
        $shift = $outScale + $bScale - $aScale;
        if ($shift >= 0) {
            $aDigits .= \str_repeat('0', $shift + 1);
        } else {
            $aDigits = \substr($aDigits, 0, \max(1, \strlen($aDigits) + $shift)) ?: '0';
            $aDigits .= '0';
        }

        $quotient = '';
        $remainder = '0';
        for ($i = 0; $i < \strlen($aDigits); ++$i) {
            $remainder = self::stripLeadingZeros($remainder.$aDigits[$i]);
            $qDigit = 0;
            while (self::cmpDigitStrings($remainder, $bDigits) >= 0) {
                $remainder = self::subDigitStrings($remainder, $bDigits);
                ++$qDigit;
            }
            $quotient .= (string) $qDigit;
        }

        return self::fromUnscaled(self::stripLeadingZeros($quotient), $outScale + 1, $sign);
    }

    private static function cmpDigitStrings(string $a, string $b): int
    {
        $a = self::stripLeadingZeros($a);
        $b = self::stripLeadingZeros($b);
        if (\strlen($a) !== \strlen($b)) {
            return \strlen($a) <=> \strlen($b);
        }

        return \strcmp($a, $b) <=> 0;
    }

    /**
     * @return array{sign:int,int:string,frac:string}
     */
    private static function parseInteger(string $num, int $argNum): array
    {
        $parsed = self::parse($num);
        if ('' !== $parsed['frac']) {
            throw new \ValueError(\sprintf(
                'bcpowmod(): Argument #%d ($%s) cannot have a fractional part',
                $argNum,
                1 === $argNum ? 'num' : (2 === $argNum ? 'exponent' : 'modulus')
            ));
        }

        return $parsed;
    }

    private static function modInt(string $left, string $right): string
    {
        $quotient = self::div($left, $right, 0);
        $product = self::mul($quotient, $right, 0);

        return self::sub($left, $product, 0);
    }

    /**
     * @param array{sign:int,int:string,frac:string} $parsed
     */
    private static function hasFractionalValue(array $parsed): bool
    {
        return '' !== \rtrim($parsed['frac'], '0');
    }

    /**
     * @param array{sign:int,int:string,frac:string} $parsed
     */
    private static function formatIntegerMagnitude(array $parsed): string
    {
        $formatted = self::format(['sign' => $parsed['sign'], 'int' => $parsed['int'], 'frac' => ''], 0);

        return '-0' === $formatted ? '0' : $formatted;
    }

    private static function divDigitStringByTwo(string $digits): string
    {
        $digits = self::stripLeadingZeros($digits);
        if ('0' === $digits) {
            return '0';
        }
        $carry = 0;
        $out = '';
        for ($i = 0; $i < \strlen($digits); ++$i) {
            $current = $carry * 10 + (int) $digits[$i];
            $out .= (string) intdiv($current, 2);
            $carry = $current % 2;
        }

        return self::stripLeadingZeros($out);
    }

    /** True when exponent is exactly 0.5 (php-src bcpow half-integer fast path). */
    private static function isHalfExponent(array $expoParsed): bool
    {
        if ($expoParsed['sign'] < 0 || '0' !== $expoParsed['int']) {
            return false;
        }
        $frac = \rtrim($expoParsed['frac'], '0');

        return '5' === $frac;
    }

    /** a^b for general fractional exponent via exp(b * ln(a)) (php-src libbcmath raise.c). */
    private static function powGeneralFractional(string $base, string $exponent, int $scale): string
    {
        $baseParsed = self::parse($base);
        if ($baseParsed['sign'] < 0) {
            throw new \ValueError('bcpow(): Argument #1 ($num) must be greater than or equal to 0 for fractional exponents');
        }
        if (self::isZero($baseParsed)) {
            return self::format(['sign' => 1, 'int' => '0', 'frac' => ''], $scale);
        }

        $workScale = max($scale + 6, 12);
        $lnBase = self::naturalLog($base, $workScale);
        $product = self::mul($exponent, $lnBase, $workScale);

        return self::format(self::parse(self::naturalExp($product, $workScale)), $scale);
    }

    private static function naturalLog(string $num, int $scale): string
    {
        $parsed = self::parse($num);
        if ($parsed['sign'] < 0 || self::isZero($parsed)) {
            throw new \ValueError('bcmath function(): Argument is not a valid number');
        }

        $workScale = $scale + 4;
        $x = self::format($parsed, $workScale);
        if (self::comp($x, '1', $workScale) < 0) {
            return self::mul('-1', self::naturalLog(self::div('1', $x, $workScale), $scale), $scale);
        }

        $u = self::div(self::sub($x, '1', $workScale), self::add($x, '1', $workScale), $workScale);
        $u2 = self::mul($u, $u, $workScale);
        $term = $u;
        $sum = $term;
        for ($k = 1; $k <= max(40, $scale + 10); ++$k) {
            $term = self::mul($term, $u2, $workScale);
            $denom = (string) (2 * $k + 1);
            $sum = self::add($sum, self::div($term, $denom, $workScale), $workScale);
        }

        return self::mul('2', $sum, $scale);
    }

    private static function naturalExp(string $num, int $scale): string
    {
        $workScale = $scale + 4;
        $term = '1';
        $sum = '1';
        for ($k = 1; $k <= max(40, $scale + 10); ++$k) {
            $term = self::mul($term, self::div($num, (string) $k, $workScale), $workScale);
            $sum = self::add($sum, $term, $workScale);
            if (0 === self::comp($term, '0', $workScale) || self::comp(self::absDecimal($term), self::pow10(-($workScale + 2)), $workScale) < 0) {
                break;
            }
        }

        return self::format(self::parse($sum), $scale);
    }

    private static function absDecimal(string $num): string
    {
        $parsed = self::parse($num);

        return self::format(['sign' => 1, 'int' => $parsed['int'], 'frac' => $parsed['frac']], \strlen($parsed['frac']));
    }

    private static function pow10(int $negExponent): string
    {
        if ($negExponent >= 0) {
            return '1'.($negExponent > 0 ? \str_repeat('0', $negExponent) : '');
        }
        $places = -$negExponent;

        return '0.'.(\str_repeat('0', $places - 1)).'1';
    }

    /**
     * @param array{sign:int,int:string,frac:string} $parsed
     */
    private static function roundOverscaleNegativePrecision(array $parsed, int $precision, int $mode): string
    {
        switch ($mode) {
            case StdlibConstants::PHP_ROUND_HALF_UP:
            case StdlibConstants::PHP_ROUND_HALF_DOWN:
            case StdlibConstants::PHP_ROUND_HALF_EVEN:
            case StdlibConstants::PHP_ROUND_HALF_ODD:
            case StdlibConstants::PHP_ROUND_TOWARD_ZERO:
                return '0';

            case StdlibConstants::PHP_ROUND_CEILING:
                if ($parsed['sign'] < 0) {
                    return '0';
                }
                break;

            case StdlibConstants::PHP_ROUND_FLOOR:
                if ($parsed['sign'] > 0) {
                    return '0';
                }
                break;

            case StdlibConstants::PHP_ROUND_AWAY_FROM_ZERO:
                break;

            default:
                return '0';
        }

        if (self::isZero($parsed)) {
            return '0';
        }

        $magnitude = -$precision;
        $power = '1'.\str_repeat('0', $magnitude);

        return ($parsed['sign'] < 0 ? '-' : '').$power;
    }

    /**
     * @param array{sign:int,int:string,frac:string} $parsed
     */
    private static function formatRoundOutput(array $parsed, int $scale): string
    {
        if (self::isZero($parsed)) {
            return '0';
        }

        $sign = $parsed['sign'] < 0 ? '-' : '';
        if (0 === $scale) {
            return $sign.$parsed['int'];
        }

        return $sign.$parsed['int'].'.'.$parsed['frac'];
    }

    private static function formatKeptBcDigits(string $kept, int $sign, int $scale): string
    {
        $kept = self::stripLeadingZeros($kept);
        if ('0' === $kept) {
            return '0';
        }

        $prefix = $sign < 0 ? '-' : '';
        if ($scale <= 0) {
            return $prefix.$kept;
        }

        if (\strlen($kept) <= $scale) {
            return $prefix.'0.'.\str_pad($kept, $scale, '0', STR_PAD_LEFT);
        }

        $intPart = self::stripLeadingZeros(\substr($kept, 0, -$scale));
        $fracPart = \substr($kept, -$scale);

        return $prefix.$intPart.'.'.$fracPart;
    }

    private static function isAllZeroDigits(string $digits): bool
    {
        return '' === $digits || '0' === self::stripLeadingZeros($digits);
    }

    /**
     * @param array{sign:int,int:string,frac:string} $parsed
     */
    private static function shouldRoundUpBc(array $parsed, int $roundedLen, int $mode): bool
    {
        $nValue = $parsed['int'].$parsed['frac'];
        $totalLen = \strlen($nValue);
        if ($roundedLen >= $totalLen) {
            return false;
        }

        $firstDigit = (int) $nValue[$roundedLen];

        switch ($mode) {
            case StdlibConstants::PHP_ROUND_HALF_UP:
                return $firstDigit >= 5;

            case StdlibConstants::PHP_ROUND_HALF_DOWN:
            case StdlibConstants::PHP_ROUND_HALF_EVEN:
            case StdlibConstants::PHP_ROUND_HALF_ODD:
                if ($firstDigit > 5) {
                    return true;
                }
                if ($firstDigit < 5) {
                    return false;
                }
                break;

            case StdlibConstants::PHP_ROUND_CEILING:
                if ($parsed['sign'] < 0) {
                    return false;
                }
                if ($firstDigit > 0) {
                    return true;
                }
                break;

            case StdlibConstants::PHP_ROUND_FLOOR:
                if ($parsed['sign'] > 0) {
                    return false;
                }
                if ($firstDigit > 0) {
                    return true;
                }
                break;

            case StdlibConstants::PHP_ROUND_TOWARD_ZERO:
                return false;

            case StdlibConstants::PHP_ROUND_AWAY_FROM_ZERO:
                if ($firstDigit > 0) {
                    return true;
                }
                break;

            default:
                return $firstDigit >= 5;
        }

        $count = $totalLen - $roundedLen - 1;
        $idx = $roundedLen + 1;
        while ($count > 0 && isset($nValue[$idx]) && '0' === $nValue[$idx]) {
            --$count;
            ++$idx;
        }
        if ($count > 0) {
            return true;
        }

        switch ($mode) {
            case StdlibConstants::PHP_ROUND_HALF_DOWN:
            case StdlibConstants::PHP_ROUND_CEILING:
            case StdlibConstants::PHP_ROUND_FLOOR:
            case StdlibConstants::PHP_ROUND_AWAY_FROM_ZERO:
                return false;

            case StdlibConstants::PHP_ROUND_HALF_EVEN:
                if ($roundedLen <= 0) {
                    return false;
                }

                return 0 !== ((int) $nValue[$roundedLen - 1] % 2);

            case StdlibConstants::PHP_ROUND_HALF_ODD:
                if ($roundedLen <= 0) {
                    return true;
                }

                return 0 === ((int) $nValue[$roundedLen - 1] % 2);

            default:
                return false;
        }
    }
}
