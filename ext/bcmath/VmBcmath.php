<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

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

    public static function add(string $left, string $right, ?int $scale = null): string
    {
        return self::format(self::addNumbers(self::parse($left), self::parse($right)), self::resolveScale($scale));
    }

    public static function sub(string $left, string $right, ?int $scale = null): string
    {
        $right = self::parse($right);
        $right['sign'] *= -1;

        return self::format(self::addNumbers(self::parse($left), $right), self::resolveScale($scale));
    }

    public static function mul(string $left, string $right, ?int $scale = null): string
    {
        $a = self::parse($left);
        $b = self::parse($right);
        $product = self::mulDigitStrings(self::unscaledDigits($a), self::unscaledDigits($b));
        $scaleSum = \strlen($a['frac']) + \strlen($b['frac']);
        $result = self::fromUnscaled($product, $scaleSum, $a['sign'] * $b['sign']);

        return self::format($result, self::resolveScale($scale));
    }

    public static function div(string $left, string $right, ?int $scale = null): string
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

        return self::format($quotient, $scale);
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

    private static function resolveScale(?int $scale): int
    {
        return null !== $scale ? $scale : self::$defaultScale;
    }

    /**
     * @return array{sign:int,int:string,frac:string}
     */
    private static function parse(string $num): array
    {
        $num = \trim($num);
        if ('' === $num) {
            throw new \ValueError('bcmath function(): Argument is not a valid number');
        }

        $sign = 1;
        if ('+' === $num[0] || '-' === $num[0]) {
            $sign = '-' === $num[0] ? -1 : 1;
            $num = \substr($num, 1);
            if ('' === $num) {
                throw new \ValueError('bcmath function(): Argument is not a valid number');
            }
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

        return [
            'sign' => $sign,
            'int' => self::stripLeadingZeros($int),
            'frac' => $frac,
        ];
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
}
