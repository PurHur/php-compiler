<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmStreamArg;

/**
 * GMP integer semantics in PHP (php-src ext/gmp/gmp.c; issues #3341, #19527, #19539, #19540, #20210).
 *
 * Phase 1–3: arithmetic + bit ops.
 * Phase 4: seedable random + import/export — no runtime/*.c growth.
 * Phase 5: prime / bit-index / number-theory (#20394, re-#19540) — no runtime/*.c growth.
 * Phase 5b: gmp_binomial (#20519) — no runtime/*.c growth.
 * Object operators (+ - * / % ** & | ^ << >> unary -/~, compare) (#21265).
 * PROFILE=8.4: null init/operand TypeError (stub int|string; #20210).
 */
final class VmGmp
{
    public const CLASS_LC = 'gmp';

    public const PROP_VALUE = 'num';

    public const GMP_MSW_FIRST = 1;
    public const GMP_LSW_FIRST = 2;
    public const GMP_LITTLE_ENDIAN = 4;
    public const GMP_BIG_ENDIAN = 8;
    public const GMP_NATIVE_ENDIAN = 16;

    /** @var int xorshift64 state (never 0) */
    private static int $rngState = 1;

    public static function isAvailable(): bool
    {
        return true;
    }

    public static function requireAvailable(string $function): void
    {
        if (!self::isAvailable()) {
            throw new \LogicException($function.'() is not supported in this compiler build (issue #3341)');
        }
    }

    public static function initObject(ObjectEntry $entry, string $signedDecimal): void
    {
        $entry->getProperty(self::PROP_VALUE)->string(self::normalizeSignedDecimal($signedDecimal));
        $entry->constructed = true;
    }

    public static function objectToSignedDecimal(ObjectEntry $entry): string
    {
        $var = $entry->getProperty(self::PROP_VALUE)->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \LogicException('GMP backing value is missing in this compiler build');
        }

        return self::normalizeSignedDecimal($var->toString());
    }

    public static function coerceGmpOperand(Variable $var, string $function, int $index, string $label): string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $resolved->type) {
            $object = $resolved->toObject();
            if (self::CLASS_LC === strtolower($object->class->name)) {
                return self::objectToSignedDecimal($object);
            }
        }

        return self::parseInitOperand($resolved, $function, $index, $label, 10);
    }

    public static function parseInitOperand(
        Variable $var,
        string $function,
        int $index,
        string $label,
        int $base
    ): string {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $resolved->type) {
            $object = $resolved->toObject();
            if (self::CLASS_LC === strtolower($object->class->name)) {
                return self::objectToSignedDecimal($object);
            }
        }
        // Z_PARAM_STR_OR_LONG / stub int|string — null soft-coerces on 8.2; TypeError on 8.4 (#20210, #18946).
        if (Variable::TYPE_NULL === $resolved->type) {
            if (version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=')) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #%d ($%s) must be of type GMP|string|int, null given',
                    $function,
                    $index + 1,
                    $label
                ));
            }

            return '0';
        }
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return (string) $resolved->toInt();
        }
        if (Variable::TYPE_STRING !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type GMP|string|int, %s given',
                $function,
                $index + 1,
                $label,
                VmStreamArg::debugTypeName($resolved)
            ));
        }

        return self::parseNumberString($resolved->toString(), $base, $function, $index, $label);
    }

    public static function parseNumberString(string $raw, int $base, string $function, int $index, string $label): string
    {
        $trimmed = trim($raw);
        if ('' === $trimmed) {
            return '0';
        }
        // php-src ext/gmp/gmp.c convert_zstr_to_gmp — base 0 auto-detects 0x/0b/0o (#25405).
        if (0 !== $base && ($base < 2 || $base > 62)) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($base) must be 0 or in the range 2..62',
                $function,
                2
            ));
        }

        $negative = false;
        if ('-' === $trimmed[0] || '+' === $trimmed[0]) {
            $negative = '-' === $trimmed[0];
            $trimmed = substr($trimmed, 1);
            if ('' === $trimmed) {
                self::throwInvalidGmpInteger($function, $index, $label, 0 === $base ? 10 : $base);
            }
        }

        $digits = $trimmed;
        $effectiveBase = $base;
        if (\strlen($digits) >= 2 && '0' === $digits[0]) {
            $marker = $digits[1];
            if ((0 === $base || 16 === $base) && ('x' === $marker || 'X' === $marker)) {
                $effectiveBase = 16;
                $digits = substr($digits, 2);
            } elseif ((0 === $base || 8 === $base) && ('o' === $marker || 'O' === $marker)) {
                $effectiveBase = 8;
                $digits = substr($digits, 2);
            } elseif ((0 === $base || 2 === $base) && ('b' === $marker || 'B' === $marker)) {
                $effectiveBase = 2;
                $digits = substr($digits, 2);
            }
        }
        if (0 === $effectiveBase) {
            $effectiveBase = 10;
        }
        if ('' === $digits) {
            self::throwInvalidGmpInteger($function, $index, $label, $effectiveBase);
        }
        if (!self::isValidDigitsForBase($digits, $effectiveBase)) {
            self::throwInvalidGmpInteger($function, $index, $label, $effectiveBase);
        }

        $decimal = self::digitsToDecimal($digits, $effectiveBase);
        if ($negative && '0' !== $decimal) {
            $decimal = '-'.$decimal;
        }

        return self::normalizeSignedDecimal($decimal);
    }

    /** @return never */
    private static function throwInvalidGmpInteger(string $function, int $index, string $label, int $base): void
    {
        throw new \ValueError(\sprintf(
            '%s(): Argument #%d ($%s) is not a valid GMP base %d integer',
            $function,
            $index + 1,
            $label,
            $base
        ));
    }

    private static function isValidDigitsForBase(string $digits, int $base): bool
    {
        $len = \strlen($digits);
        for ($i = 0; $i < $len; ++$i) {
            $value = self::digitValue($digits[$i]);
            if ($value < 0 || $value >= $base) {
                return false;
            }
        }

        return true;
    }

    private static function digitValue(string $ch): int
    {
        $ord = \ord($ch);
        if ($ord >= 48 && $ord <= 57) {
            return $ord - 48;
        }
        if ($ord >= 65 && $ord <= 90) {
            return $ord - 55;
        }
        if ($ord >= 97 && $ord <= 122) {
            return $ord - 87;
        }

        return -1;
    }

    private static function digitsToDecimal(string $digits, int $base): string
    {
        if (10 === $base) {
            return ltrim($digits, '0') ?: '0';
        }
        $baseStr = (string) $base;
        $acc = '0';
        $len = \strlen($digits);
        for ($i = 0; $i < $len; ++$i) {
            $acc = self::mulMagnitude($acc, $baseStr);
            $acc = self::addMagnitude($acc, (string) self::digitValue($digits[$i]));
        }

        return $acc;
    }

    public static function add(string $left, string $right): string
    {
        return self::normalizeSignedDecimal(self::addSigned(self::normalizeSignedDecimal($left), self::normalizeSignedDecimal($right)));
    }

    public static function sub(string $left, string $right): string
    {
        return self::add($left, self::negate($right));
    }

    public static function mul(string $left, string $right): string
    {
        $a = self::splitSign(self::normalizeSignedDecimal($left));
        $b = self::splitSign(self::normalizeSignedDecimal($right));
        $product = self::mulMagnitude($a['mag'], $b['mag']);
        $sign = $a['neg'] !== $b['neg'] ? '-' : '';
        if ('0' === $product) {
            return '0';
        }

        return self::normalizeSignedDecimal($sign.$product);
    }

    public static function cmp(string $left, string $right): int
    {
        $a = self::normalizeSignedDecimal($left);
        $b = self::normalizeSignedDecimal($right);
        if ($a === $b) {
            return 0;
        }
        $sa = self::splitSign($a);
        $sb = self::splitSign($b);
        if ($sa['neg'] !== $sb['neg']) {
            return $sa['neg'] ? -1 : 1;
        }
        $cmp = self::cmpMagnitude($sa['mag'], $sb['mag']);

        return $sa['neg'] ? -$cmp : $cmp;
    }

    public static function strval(string $signedDecimal, int $base = 10): string
    {
        $normalized = self::normalizeSignedDecimal($signedDecimal);
        if (10 === $base) {
            return $normalized;
        }
        if (16 === $base) {
            $parts = self::splitSign($normalized);
            if ('0' === $parts['mag']) {
                return '0';
            }
            $hex = self::decimalToHex($parts['mag']);

            return $parts['neg'] ? '-'.$hex : $hex;
        }

        throw new \ValueError('gmp_strval(): Base must be 10 or 16 in this compiler build');
    }

    public static function abs(string $signedDecimal): string
    {
        $parts = self::splitSign(self::normalizeSignedDecimal($signedDecimal));

        return $parts['mag'];
    }

    public static function neg(string $signedDecimal): string
    {
        return self::negate(self::normalizeSignedDecimal($signedDecimal));
    }

    public static function pow(string $base, int $exponent): string
    {
        if ($exponent < 0) {
            throw new \ValueError('gmp_pow(): Argument #2 ($exponent) must be greater than or equal to 0');
        }
        if (0 === $exponent) {
            return '1';
        }
        $result = '1';
        $b = self::normalizeSignedDecimal($base);
        $e = $exponent;
        while ($e > 0) {
            if (0 !== ($e & 1)) {
                $result = self::mul($result, $b);
            }
            $e >>= 1;
            if ($e > 0) {
                $b = self::mul($b, $b);
            }
        }

        return $result;
    }

    /**
     * Toward-zero quotient and remainder (mpz_tdiv_q / mpz_tdiv_r).
     *
     * @return array{0: string, 1: string}
     */
    public static function divQr(string $left, string $right): array
    {
        $divisor = self::normalizeSignedDecimal($right);
        if ('0' === $divisor) {
            throw new \DivisionByZeroError('Division by zero');
        }
        $a = self::splitSign(self::normalizeSignedDecimal($left));
        $b = self::splitSign($divisor);
        [$qMag, $rMag] = self::divModMagnitude($a['mag'], $b['mag']);
        $qSign = ($a['neg'] !== $b['neg']) ? '-' : '';
        $rSign = $a['neg'] ? '-' : '';
        $q = ('0' === $qMag) ? '0' : self::normalizeSignedDecimal($qSign.$qMag);
        $r = ('0' === $rMag) ? '0' : self::normalizeSignedDecimal($rSign.$rMag);

        return [$q, $r];
    }

    public static function divQ(string $left, string $right): string
    {
        return self::divQr($left, $right)[0];
    }

    public static function divR(string $left, string $right): string
    {
        return self::divQr($left, $right)[1];
    }

    /** Non-negative remainder (mpz_mod). */
    public static function mod(string $left, string $right): string
    {
        $divisor = self::normalizeSignedDecimal($right);
        if ('0' === $divisor) {
            throw new \DivisionByZeroError('Division by zero');
        }
        $r = self::divR($left, $divisor);
        if (self::cmp($r, '0') < 0) {
            $r = self::add($r, self::abs($divisor));
        }

        return $r;
    }

    public static function bitwiseAnd(string $left, string $right): string
    {
        return self::bitwiseOp($left, $right, 'and');
    }

    public static function bitwiseOr(string $left, string $right): string
    {
        return self::bitwiseOp($left, $right, 'or');
    }

    public static function bitwiseXor(string $left, string $right): string
    {
        return self::bitwiseOp($left, $right, 'xor');
    }

    /** mpz_mul_2exp — left << shift (php-src gmp_do_operation ZEND_SL). */
    public static function shiftLeft(string $left, int $shift): string
    {
        if ($shift < 0) {
            throw new \ValueError('Shift must be greater than or equal to 0');
        }
        if (0 === $shift) {
            return self::normalizeSignedDecimal($left);
        }

        return self::mul($left, self::pow('2', $shift));
    }

    /** mpz_fdiv_q_2exp — left >> shift, floor toward −∞ (php-src gmp_do_operation ZEND_SR). */
    public static function shiftRight(string $left, int $shift): string
    {
        if ($shift < 0) {
            throw new \ValueError('Shift must be greater than or equal to 0');
        }
        if (0 === $shift) {
            return self::normalizeSignedDecimal($left);
        }
        $divisor = self::pow('2', $shift);
        [$q, $r] = self::divQr($left, $divisor);
        // Floor when negative with nonzero remainder (tdiv → fdiv).
        if (self::cmp($left, '0') < 0 && '0' !== $r) {
            $q = self::sub($q, '1');
        }

        return $q;
    }

    /** Truncate like mpz_get_si into PHP int. */
    public static function toInt(string $signedDecimal): int
    {
        $normalized = self::normalizeSignedDecimal($signedDecimal);
        if (self::cmp($normalized, (string) \PHP_INT_MAX) <= 0
            && self::cmp($normalized, (string) \PHP_INT_MIN) >= 0) {
            return (int) $normalized;
        }
        $width = \PHP_INT_SIZE * 8;
        $bits = self::toTwosComplementBits($normalized, $width);

        return self::signedIntFromBits($bits);
    }

    public static function coerceExponent(Variable $var, string $function): int
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return $resolved->toInt();
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            return 0;
        }
        if (Variable::TYPE_STRING === $resolved->type) {
            $raw = trim($resolved->toString());
            if ('' === $raw || !preg_match('/^[+-]?[0-9]+$/', $raw)) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #2 ($exponent) must be of type int, string given',
                    $function
                ));
            }
            if (self::cmp($raw, (string) \PHP_INT_MAX) > 0 || self::cmp($raw, (string) \PHP_INT_MIN) < 0) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #2 ($exponent) must be of type int, string given',
                    $function
                ));
            }

            return (int) $raw;
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #2 ($exponent) must be of type int, %s given',
            $function,
            VmStreamArg::debugTypeName($resolved)
        ));
    }

    /** Modular exponentiation (mpz_powm). */
    public static function powm(string $base, string $exponent, string $modulus): string
    {
        $mod = self::normalizeSignedDecimal($modulus);
        if ('0' === $mod) {
            throw new \DivisionByZeroError('Division by zero');
        }
        $exp = self::normalizeSignedDecimal($exponent);
        if (self::cmp($exp, '0') < 0) {
            throw new \ValueError('gmp_powm(): Argument #2 ($exponent) must be greater than or equal to 0');
        }
        $m = self::abs($mod);
        if ('1' === $m) {
            return '0';
        }
        $result = '1';
        $b = self::mod($base, $m);
        $eMag = self::splitSign($exp)['mag'];
        while ('0' !== $eMag) {
            [$eMag, $bit] = self::divModSmall($eMag, 2);
            if (1 === $bit) {
                $result = self::mod(self::mul($result, $b), $m);
            }
            if ('0' !== $eMag) {
                $b = self::mod(self::mul($b, $b), $m);
            }
        }

        return $result;
    }

    public static function fact(string $num): string
    {
        $n = self::normalizeSignedDecimal($num);
        if (self::cmp($n, '0') < 0) {
            throw new \ValueError('gmp_fact(): Argument #1 ($num) must be greater than or equal to 0');
        }
        $result = '1';
        $i = '2';
        while (self::cmp($i, $n) <= 0) {
            $result = self::mul($result, $i);
            $i = self::add($i, '1');
        }

        return $result;
    }

    /**
     * Binomial coefficient C(n, k) — php-src gmp.c mpz_bin_ui / mpz_bin_uiui (#20519).
     *
     * Multiplicative product stays exact for integer n and k >= 0 (including negative n).
     */
    public static function binomial(string $n, int $k): string
    {
        if ($k < 0) {
            throw new \ValueError('gmp_binomial(): Argument #2 ($k) must be greater than or equal to 0');
        }
        if (0 === $k) {
            return '1';
        }
        $nNorm = self::normalizeSignedDecimal($n);
        if (self::cmp($nNorm, '0') >= 0) {
            if (self::cmp($nNorm, (string) $k) < 0) {
                return '0';
            }
            // C(n,k) == C(n, n-k); shrink loop when k is large.
            $nMinusK = self::sub($nNorm, (string) $k);
            if (self::cmp((string) $k, $nMinusK) > 0) {
                if (self::cmp($nMinusK, (string) \PHP_INT_MAX) > 0) {
                    throw new \ValueError('gmp_binomial(): Argument #2 ($k) is too large in this compiler build');
                }
                $k = (int) $nMinusK;
                if (0 === $k) {
                    return '1';
                }
            }
        }

        $result = '1';
        for ($i = 1; $i <= $k; ++$i) {
            $term = self::sub($nNorm, (string) ($k - $i));
            $result = self::divQ(self::mul($result, $term), (string) $i);
        }

        return $result;
    }

    /**
     * Z_PARAM_LONG $k for gmp_binomial (php-src "zl"; same coercion shape as gmp_pow $exponent).
     *
     * @throws \TypeError
     */
    public static function coerceBinomialK(Variable $var): int
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return $resolved->toInt();
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            return 0;
        }
        if (Variable::TYPE_STRING === $resolved->type) {
            $raw = trim($resolved->toString());
            if ('' === $raw || !preg_match('/^[+-]?[0-9]+$/', $raw)) {
                throw new \TypeError('gmp_binomial(): Argument #2 ($k) must be of type int, string given');
            }
            if (self::cmp($raw, (string) \PHP_INT_MAX) > 0 || self::cmp($raw, (string) \PHP_INT_MIN) < 0) {
                throw new \TypeError('gmp_binomial(): Argument #2 ($k) must be of type int, string given');
            }

            return (int) $raw;
        }

        throw new \TypeError(\sprintf(
            'gmp_binomial(): Argument #2 ($k) must be of type int, %s given',
            VmStreamArg::debugTypeName($resolved)
        ));
    }

    public static function gcd(string $left, string $right): string
    {
        $x = self::abs($left);
        $y = self::abs($right);
        while ('0' !== $y) {
            $t = self::mod($x, $y);
            $x = $y;
            $y = $t;
        }

        return $x;
    }

    public static function lcm(string $left, string $right): string
    {
        $a = self::abs($left);
        $b = self::abs($right);
        if ('0' === $a || '0' === $b) {
            return '0';
        }

        return self::divQ(self::mul($a, $b), self::gcd($a, $b));
    }

    public static function sqrt(string $a): string
    {
        $n = self::normalizeSignedDecimal($a);
        if (self::cmp($n, '0') < 0) {
            throw new \ValueError('gmp_sqrt(): Argument #1 ($a) must be greater than or equal to 0');
        }
        if (self::cmp($n, '1') <= 0) {
            return $n;
        }
        $low = '1';
        $high = $n;
        $ans = '1';
        while (self::cmp($low, $high) <= 0) {
            $mid = self::divQ(self::add($low, $high), '2');
            $sq = self::mul($mid, $mid);
            $cmp = self::cmp($sq, $n);
            if (0 === $cmp) {
                return $mid;
            }
            if ($cmp < 0) {
                $ans = $mid;
                $low = self::add($mid, '1');
            } else {
                $high = self::sub($mid, '1');
            }
        }

        return $ans;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function sqrtrem(string $a): array
    {
        $root = self::sqrt($a);
        $rem = self::sub(self::normalizeSignedDecimal($a), self::mul($root, $root));

        return [$root, $rem];
    }

    public static function perfectSquare(string $a): bool
    {
        $n = self::normalizeSignedDecimal($a);
        if (self::cmp($n, '0') < 0) {
            return false;
        }
        $root = self::sqrt($n);

        return 0 === self::cmp(self::mul($root, $root), $n);
    }

    /** mpz_sgn */
    public static function sign(string $a): int
    {
        $cmp = self::cmp(self::normalizeSignedDecimal($a), '0');
        if (0 === $cmp) {
            return 0;
        }

        return $cmp < 0 ? -1 : 1;
    }

    /** mpz_tstbit — two's-complement bit test. */
    public static function testbit(string $a, int $index): bool
    {
        self::requireBitIndex($index, 'gmp_testbit');
        $bits = self::toTwosComplementBits(self::normalizeSignedDecimal($a), $index + 2);

        return '1' === $bits[strlen($bits) - 1 - $index];
    }

    /** mpz_setbit / mpz_clrbit semantics on a decimal encoding. */
    public static function withBit(string $a, int $index, bool $set): string
    {
        self::requireBitIndex($index, $set ? 'gmp_setbit' : 'gmp_clrbit');
        $n = self::normalizeSignedDecimal($a);
        $width = max(self::bitLengthMagnitude(self::splitSign($n)['mag']) + 2, $index + 2);
        $bits = self::toTwosComplementBits($n, $width);
        $pos = strlen($bits) - 1 - $index;
        $bits[$pos] = $set ? '1' : '0';

        return self::fromTwosComplementBits($bits);
    }

    /** mpz_scan0 — first 0 bit at or after $start. */
    public static function scan0(string $a, int $start): int
    {
        self::requireBitIndex($start, 'gmp_scan0');
        $n = self::normalizeSignedDecimal($a);
        $width = max(self::bitLengthMagnitude(self::splitSign($n)['mag']) + 8, $start + 8);
        $bits = self::toTwosComplementBits($n, $width);
        for ($i = $start; $i < $width; ++$i) {
            if ('0' === $bits[strlen($bits) - 1 - $i]) {
                return $i;
            }
        }
        if (self::cmp($n, '0') >= 0) {
            return $width;
        }

        return -1;
    }

    /** mpz_scan1 — first 1 bit at or after $start (-1 if none for non-negative). */
    public static function scan1(string $a, int $start): int
    {
        self::requireBitIndex($start, 'gmp_scan1');
        $n = self::normalizeSignedDecimal($a);
        if (self::cmp($n, '0') < 0) {
            $width = max(self::bitLengthMagnitude(self::splitSign($n)['mag']) + 8, $start + 2);
            $bits = self::toTwosComplementBits($n, $width);
            for ($i = $start; $i < $width; ++$i) {
                if ('1' === $bits[strlen($bits) - 1 - $i]) {
                    return $i;
                }
            }

            return $start;
        }
        $magBits = self::bitLengthMagnitude(self::splitSign($n)['mag']);
        if ($start >= $magBits) {
            return -1;
        }
        $bits = self::toTwosComplementBits($n, max($magBits + 1, $start + 1));
        for ($i = $start; $i < strlen($bits); ++$i) {
            if ('1' === $bits[strlen($bits) - 1 - $i]) {
                return $i;
            }
        }

        return -1;
    }

    /** mpz_popcount — negatives surface as -1 (ULONG_MAX as zend_long). */
    public static function popcount(string $a): int
    {
        $n = self::normalizeSignedDecimal($a);
        if (self::cmp($n, '0') < 0) {
            return -1;
        }
        $bits = self::magnitudeToBits(self::splitSign($n)['mag']);
        $count = 0;
        $len = strlen($bits);
        for ($i = 0; $i < $len; ++$i) {
            if ('1' === $bits[$i]) {
                ++$count;
            }
        }

        return $count;
    }

    /** mpz_hamdist — popcount of xor (non-negative operands). */
    public static function hamdist(string $left, string $right): int
    {
        $a = self::normalizeSignedDecimal($left);
        $b = self::normalizeSignedDecimal($right);
        if (self::cmp($a, '0') < 0 || self::cmp($b, '0') < 0) {
            return -1;
        }

        return self::popcount(self::bitwiseXor($a, $b));
    }

    /**
     * Extended gcd — [g, s, t] with a*s + b*t = g >= 0 (mpz_gcdext).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function gcdext(string $left, string $right): array
    {
        $a = self::normalizeSignedDecimal($left);
        $b = self::normalizeSignedDecimal($right);
        $oldR = $a;
        $r = $b;
        $oldS = '1';
        $s = '0';
        $oldT = '0';
        $t = '1';
        while ('0' !== $r) {
            [$q, $rem] = self::divQr($oldR, $r);
            $oldR = $r;
            $r = $rem;
            $nextS = self::sub($oldS, self::mul($q, $s));
            $oldS = $s;
            $s = $nextS;
            $nextT = self::sub($oldT, self::mul($q, $t));
            $oldT = $t;
            $t = $nextT;
        }
        $g = $oldR;
        $sOut = $oldS;
        $tOut = $oldT;
        if (self::cmp($g, '0') < 0) {
            $g = self::neg($g);
            $sOut = self::neg($sOut);
            $tOut = self::neg($tOut);
        }

        return [$g, $sOut, $tOut];
    }

    /** mpz_invert — modular inverse or null when missing. */
    public static function invert(string $num, string $modulus): ?string
    {
        $mod = self::normalizeSignedDecimal($modulus);
        if ('0' === $mod) {
            throw new \DivisionByZeroError('Division by zero');
        }
        $m = self::abs($mod);
        [$g, $s] = self::gcdext($num, $m);
        if ('1' !== $g) {
            return null;
        }

        return self::mod($s, $m);
    }

    /** mpz_jacobi */
    public static function jacobi(string $a, string $n): int
    {
        return self::kroneckerJacobi(
            self::normalizeSignedDecimal($a),
            self::normalizeSignedDecimal($n)
        );
    }

    /** mpz_kronecker — full Kronecker symbol (#20586). */
    public static function kronecker(string $a, string $n): int
    {
        return self::kroneckerJacobi(
            self::normalizeSignedDecimal($a),
            self::normalizeSignedDecimal($n)
        );
    }

    /** mpz_divexact — exact division; DivisionByZeroError on zero divisor (#20586). */
    public static function divExact(string $left, string $right): string
    {
        // php-src gmp_binary_ui_op_no_zero(mpz_divexact): undefined if not exact; we use toward-zero quotient.
        return self::divQ($left, $right);
    }

    /** mpz_legendre */
    public static function legendre(string $a, string $p): int
    {
        return self::jacobi($a, $p);
    }

    public static function root(string $a, int $nth): string
    {
        return self::rootrem($a, $nth)[0];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function rootrem(string $a, int $nth): array
    {
        if ($nth < 1) {
            throw new \ValueError('gmp_root(): Argument #2 ($nth) must be greater than or equal to 1');
        }
        $n = self::normalizeSignedDecimal($a);
        $neg = self::cmp($n, '0') < 0;
        if ($neg && 0 === ($nth % 2)) {
            throw new \ValueError('gmp_root(): Argument #2 ($nth) must be odd if argument #1 ($a) is negative');
        }
        if (1 === $nth) {
            return [$n, '0'];
        }
        $mag = self::abs($n);
        if (self::cmp($mag, '1') <= 0) {
            return [$n, '0'];
        }
        $low = '1';
        $high = $mag;
        $ans = '1';
        while (self::cmp($low, $high) <= 0) {
            $mid = self::divQ(self::add($low, $high), '2');
            $pow = self::pow($mid, $nth);
            $cmp = self::cmp($pow, $mag);
            if (0 === $cmp) {
                $ans = $mid;
                break;
            }
            if ($cmp < 0) {
                $ans = $mid;
                $low = self::add($mid, '1');
            } else {
                $high = self::sub($mid, '1');
            }
        }
        if ($neg) {
            $ans = self::neg($ans);
        }
        $rem = self::sub($n, self::pow($ans, $nth));

        return [$ans, $rem];
    }

    public static function perfectPower(string $a): bool
    {
        $n = self::normalizeSignedDecimal($a);
        if (self::cmp($n, '0') < 0) {
            $mag = self::abs($n);
            $maxExp = min(64, max(2, self::bitLengthMagnitude(self::splitSign($mag)['mag'])));
            for ($e = 3; $e <= $maxExp; $e += 2) {
                $r = self::root($mag, $e);
                if (0 === self::cmp(self::pow($r, $e), $mag)) {
                    return true;
                }
            }

            return false;
        }
        if (self::cmp($n, '1') <= 0) {
            return true;
        }
        $maxExp = min(64, max(2, self::bitLengthMagnitude(self::splitSign($n)['mag'])));
        for ($e = 2; $e <= $maxExp; ++$e) {
            $r = self::root($n, $e);
            if (0 === self::cmp(self::pow($r, $e), $n)) {
                return true;
            }
        }

        return false;
    }

    /** Miller-Rabin: 0 composite, 1 probable, 2 definite. */
    public static function probPrime(string $a, int $repetitions = 10): int
    {
        if ($repetitions < 0) {
            $repetitions = 0;
        }
        $n = self::normalizeSignedDecimal($a);
        if (self::cmp($n, '2') < 0) {
            return 0;
        }
        if ('2' === $n || '3' === $n) {
            return 2;
        }
        if (0 === self::toInt(self::mod($n, '2'))) {
            return 0;
        }
        foreach (['5', '7', '11', '13', '17', '19', '23', '29', '31'] as $p) {
            if ($n === $p) {
                return 2;
            }
            if ('0' === self::mod($n, $p) && self::cmp($n, $p) > 0) {
                return 0;
            }
        }
        $nm1 = self::sub($n, '1');
        $d = $nm1;
        $s = 0;
        while ('0' === self::mod($d, '2')) {
            $d = self::divQ($d, '2');
            ++$s;
        }
        $witnesses = self::millerRabinWitnesses($n, $repetitions);
        $definite = true;
        foreach ($witnesses as $w) {
            if (self::cmp($w, '0') <= 0 || self::cmp($w, $n) >= 0) {
                continue;
            }
            $x = self::powm($w, $d, $n);
            if ('1' === $x || 0 === self::cmp($x, $nm1)) {
                continue;
            }
            $composite = true;
            for ($r = 1; $r < $s; ++$r) {
                $x = self::mod(self::mul($x, $x), $n);
                if (0 === self::cmp($x, $nm1)) {
                    $composite = false;
                    break;
                }
                if ('1' === $x) {
                    break;
                }
            }
            if ($composite) {
                return 0;
            }
            $definite = false;
        }

        return $definite ? 2 : 1;
    }

    public static function nextprime(string $a): string
    {
        $n = self::normalizeSignedDecimal($a);
        if (self::cmp($n, '2') < 0) {
            return '2';
        }
        $p = self::add($n, '1');
        if (0 === self::toInt(self::mod($p, '2'))) {
            $p = self::add($p, '1');
        }
        while (0 === self::probPrime($p, 15)) {
            $p = self::add($p, '2');
        }

        return $p;
    }

    public static function coerceBitIndex(Variable $var, string $function, int $index, string $label): int
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $index + 1,
                $label,
                VmStreamArg::debugTypeName($resolved)
            ));
        }

        return $resolved->toInt();
    }

    public static function coerceRepetitions(Variable $var): int
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $resolved->type) {
            throw new \TypeError('gmp_prob_prime(): Argument #2 ($repetitions) must be of type int, '
                .VmStreamArg::debugTypeName($resolved).' given');
        }

        return $resolved->toInt();
    }

    public static function coerceNth(Variable $var, string $function): int
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #2 ($nth) must be of type int, %s given',
                $function,
                VmStreamArg::debugTypeName($resolved)
            ));
        }

        return $resolved->toInt();
    }

    /** Bitwise complement (~n == -n-1). */
    public static function com(string $a): string
    {
        return self::sub(self::neg($a), '1');
    }

    public static function randomSeed(string $seed): void
    {
        $normalized = self::normalizeSignedDecimal($seed);
        $bits = self::toTwosComplementBits($normalized, 64);
        $state = 0;
        for ($i = 0; $i < 64; ++$i) {
            $state = ($state << 1) | ('1' === $bits[$i] ? 1 : 0);
        }
        if (0 === $state) {
            $state = 1;
        }
        self::$rngState = $state;
    }

    public static function randomBits(int $bits): string
    {
        if ($bits < 1) {
            throw new \ValueError('gmp_random_bits(): Argument #1 ($bits) must be greater than or equal to 1');
        }
        $acc = '0';
        $remaining = $bits;
        while ($remaining > 0) {
            $chunk = min(32, $remaining);
            $r = self::nextRngUint32();
            if ($chunk < 32) {
                $r &= (1 << $chunk) - 1;
            }
            $acc = self::add(self::mul($acc, self::pow('2', $chunk)), (string) $r);
            $remaining -= $chunk;
        }

        return $acc;
    }

    public static function randomRange(string $min, string $max): string
    {
        $a = self::normalizeSignedDecimal($min);
        $b = self::normalizeSignedDecimal($max);
        if (self::cmp($a, $b) > 0) {
            throw new \ValueError('gmp_random_range(): Argument #1 ($min) must be less than or equal to argument #2 ($max)');
        }
        if ($a === $b) {
            return $a;
        }
        $span = self::add(self::sub($b, $a), '1');
        // Rejection sampling with enough bits for span
        $bits = max(1, self::bitLengthMagnitude(self::splitSign($span)['mag']));
        do {
            $r = self::randomBits($bits);
        } while (self::cmp($r, $span) >= 0);

        return self::add($a, $r);
    }

    public static function import(string $data, int $wordSize = 1, int $flags = self::GMP_MSW_FIRST | self::GMP_NATIVE_ENDIAN): string
    {
        if ($wordSize < 1) {
            throw new \ValueError('gmp_import(): Argument #2 ($word_size) must be greater than or equal to 1');
        }
        [$order, $endian] = self::parseImportExportFlags($flags);
        $len = strlen($data);
        if (0 === $len) {
            throw new \ValueError('gmp_import(): Argument #1 ($data) must not be empty');
        }
        if (0 !== ($len % $wordSize)) {
            throw new \ValueError('gmp_import(): Argument #1 ($data) must be a multiple of argument #2 ($word_size)');
        }
        $words = [];
        for ($i = 0; $i < $len; $i += $wordSize) {
            $word = substr($data, $i, $wordSize);
            if (-1 === $endian || (0 === $endian && self::nativeEndianIsLittle())) {
                $word = strrev($word);
            }
            $words[] = $word;
        }
        if (-1 === $order) {
            $words = array_reverse($words);
        }
        $acc = '0';
        foreach ($words as $word) {
            for ($j = 0; $j < $wordSize; ++$j) {
                $acc = self::add(self::mul($acc, '256'), (string) ord($word[$j]));
            }
        }

        return $acc;
    }

    public static function export(string $num, int $wordSize = 1, int $flags = self::GMP_MSW_FIRST | self::GMP_NATIVE_ENDIAN): string
    {
        if ($wordSize < 1) {
            throw new \ValueError('gmp_export(): Argument #2 ($word_size) must be greater than or equal to 1');
        }
        [$order, $endian] = self::parseImportExportFlags($flags);
        $n = self::abs($num);
        if ('0' === $n) {
            return '';
        }
        $bytes = [];
        while ('0' !== $n) {
            [$n, $rem] = self::divModSmall($n, 256);
            $bytes[] = chr($rem);
        }
        $bytes = array_reverse($bytes); // MSW byte first
        $pad = (count($bytes) % $wordSize);
        if (0 !== $pad) {
            $bytes = array_merge(array_fill(0, $wordSize - $pad, "\0"), $bytes);
        }
        $words = [];
        for ($i = 0; $i < count($bytes); $i += $wordSize) {
            $word = implode('', array_slice($bytes, $i, $wordSize));
            if (-1 === $endian || (0 === $endian && self::nativeEndianIsLittle())) {
                $word = strrev($word);
            }
            $words[] = $word;
        }
        if (-1 === $order) {
            $words = array_reverse($words);
        }

        return implode('', $words);
    }

    /** @return array{0: int, 1: int} order (1=MSW,-1=LSW), endian (1=big,-1=little,0=native) */
    private static function parseImportExportFlags(int $flags): array
    {
        $orderBits = $flags & (self::GMP_LSW_FIRST | self::GMP_MSW_FIRST);
        $endianBits = $flags & (self::GMP_LITTLE_ENDIAN | self::GMP_BIG_ENDIAN | self::GMP_NATIVE_ENDIAN);
        $order = match ($orderBits) {
            self::GMP_LSW_FIRST => -1,
            self::GMP_MSW_FIRST, 0 => 1,
            default => throw new \ValueError('gmp_import(): Argument #3 ($flags) cannot use multiple word order options'),
        };
        $endian = match ($endianBits) {
            self::GMP_LITTLE_ENDIAN => -1,
            self::GMP_BIG_ENDIAN => 1,
            self::GMP_NATIVE_ENDIAN, 0 => 0,
            default => throw new \ValueError('gmp_import(): Argument #3 ($flags) cannot use multiple endian options'),
        };

        return [$order, $endian];
    }

    private static function nativeEndianIsLittle(): bool
    {
        return "\x01\x00" === pack('v', 1);
    }

    private static function nextRngUint32(): int
    {
        $x = self::$rngState;
        $x ^= ($x << 13);
        $x ^= ($x >> 7);
        $x ^= ($x << 17);
        if (0 === $x) {
            $x = 1;
        }
        self::$rngState = $x;

        return $x & 0xFFFFFFFF;
    }

    private static function requireBitIndex(int $index, string $function): void
    {
        if ($index < 0) {
            throw new \ValueError($function.'(): Argument #2 must be greater than or equal to 0');
        }
    }

    /** @return list<string> */
    private static function millerRabinWitnesses(string $n, int $repetitions): array
    {
        $bases = ['2', '3', '5', '7', '11', '13', '23', '29', '31', '37'];
        if ($repetitions <= 0) {
            return array_slice($bases, 0, 1);
        }
        $out = [];
        foreach ($bases as $b) {
            if (self::cmp($b, $n) >= 0) {
                break;
            }
            $out[] = $b;
            if (count($out) >= $repetitions) {
                return $out;
            }
        }

        return $out;
    }

    /** Jacobi/Kronecker symbol (a/n). */
    private static function kroneckerJacobi(string $a, string $n): int
    {
        if ('0' === $n) {
            return 0 === self::cmp($a, '0') || '1' === self::abs($a) ? 1 : 0;
        }
        $aa = $a;
        $nn = $n;
        // mpz_kronecker: both even → 0
        if ('0' === self::mod($aa, '2') && '0' === self::mod($nn, '2')) {
            return 0;
        }
        $result = 1;
        if (self::cmp($nn, '0') < 0) {
            $nn = self::neg($nn);
            if (self::cmp($aa, '0') < 0) {
                $result = -$result;
            }
        }
        $trail = 0;
        while ('0' === self::mod($nn, '2')) {
            $nn = self::divQ($nn, '2');
            ++$trail;
        }
        if ($trail > 0 && 0 !== ($trail % 2)) {
            $amod8 = self::toInt(self::mod(self::abs($aa), '8'));
            if (3 === $amod8 || 5 === $amod8) {
                $result = -$result;
            }
        }
        if ('1' === $nn) {
            return 0 === self::cmp($aa, '0') ? 0 : $result;
        }
        $aa = self::mod($aa, $nn);
        while ('0' !== $aa) {
            $trailA = 0;
            while ('0' === self::mod($aa, '2')) {
                $aa = self::divQ($aa, '2');
                ++$trailA;
            }
            if (0 !== ($trailA % 2)) {
                $nmod8 = self::toInt(self::mod($nn, '8'));
                if (3 === $nmod8 || 5 === $nmod8) {
                    $result = -$result;
                }
            }
            $amod4 = self::toInt(self::mod($aa, '4'));
            $nmod4 = self::toInt(self::mod($nn, '4'));
            if (3 === $amod4 && 3 === $nmod4) {
                $result = -$result;
            }
            $tmp = $aa;
            $aa = self::mod($nn, $tmp);
            $nn = $tmp;
        }

        return '1' === $nn ? $result : 0;
    }

    private static function normalizeSignedDecimal(string $value): string
    {
        $trimmed = trim($value);
        if ('' === $trimmed || '+' === $trimmed || '-' === $trimmed) {
            return '0';
        }
        $negative = str_starts_with($trimmed, '-');
        $digits = ltrim($negative ? substr($trimmed, 1) : ($trimmed[0] === '+' ? substr($trimmed, 1) : $trimmed), '0');
        if ('' === $digits) {
            return '0';
        }

        return $negative ? '-'.$digits : $digits;
    }

    /** @return array{neg: bool, mag: string} */
    private static function splitSign(string $signed): array
    {
        if ('0' === $signed) {
            return ['neg' => false, 'mag' => '0'];
        }
        $negative = str_starts_with($signed, '-');

        return [
            'neg' => $negative,
            'mag' => $negative ? substr($signed, 1) : $signed,
        ];
    }

    private static function negate(string $signed): string
    {
        $normalized = self::normalizeSignedDecimal($signed);
        if ('0' === $normalized) {
            return '0';
        }
        if (str_starts_with($normalized, '-')) {
            return substr($normalized, 1);
        }

        return '-'.$normalized;
    }

    private static function addSigned(string $left, string $right): string
    {
        $a = self::splitSign($left);
        $b = self::splitSign($right);
        if ($a['neg'] === $b['neg']) {
            $sum = self::addMagnitude($a['mag'], $b['mag']);
            if ('0' === $sum) {
                return '0';
            }

            return ($a['neg'] ? '-' : '').$sum;
        }
        $cmp = self::cmpMagnitude($a['mag'], $b['mag']);
        if (0 === $cmp) {
            return '0';
        }
        if ($cmp > 0) {
            $diff = self::subMagnitude($a['mag'], $b['mag']);

            return ('0' === $diff) ? '0' : (($a['neg'] ? '-' : '').$diff);
        }
        $diff = self::subMagnitude($b['mag'], $a['mag']);

        return ('0' === $diff) ? '0' : (($b['neg'] ? '-' : '').$diff);
    }

    private static function addMagnitude(string $a, string $b): string
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        if ('0' === $a) {
            return $b;
        }
        if ('0' === $b) {
            return $a;
        }
        $carry = 0;
        $result = '';
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry;
            if ($i >= 0) {
                $sum += ord($a[$i]) - 48;
                --$i;
            }
            if ($j >= 0) {
                $sum += ord($b[$j]) - 48;
                --$j;
            }
            $result = (string) ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function subMagnitude(string $a, string $b): string
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        $borrow = 0;
        $result = '';
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0) {
            $diff = (ord($a[$i]) - 48) - $borrow - (($j >= 0) ? (ord($b[$j]) - 48) : 0);
            if ($diff < 0) {
                $diff += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = (string) $diff.$result;
            --$i;
            if ($j >= 0) {
                --$j;
            }
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function cmpMagnitude(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        if (strlen($a) !== strlen($b)) {
            return strlen($a) > strlen($b) ? 1 : -1;
        }

        return $a <=> $b;
    }

    private static function mulMagnitude(string $a, string $b): string
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        if ('0' === $a || '0' === $b) {
            return '0';
        }
        if (strlen($a) < strlen($b)) {
            [$a, $b] = [$b, $a];
        }
        $acc = '0';
        $shift = 0;
        for ($j = strlen($b) - 1; $j >= 0; --$j) {
            $digit = ord($b[$j]) - 48;
            if (0 === $digit) {
                ++$shift;
                continue;
            }
            $partial = self::mulSingleDigit($a, $digit);
            if ($shift > 0) {
                $partial .= str_repeat('0', $shift);
            }
            $acc = self::addMagnitude($acc, $partial);
            ++$shift;
        }

        return $acc;
    }

    private static function mulSingleDigit(string $a, int $digit): string
    {
        $carry = 0;
        $result = '';
        for ($i = strlen($a) - 1; $i >= 0; --$i) {
            $prod = (ord($a[$i]) - 48) * $digit + $carry;
            $result = (string) ($prod % 10).$result;
            $carry = intdiv($prod, 10);
        }
        if ($carry > 0) {
            $result = (string) $carry.$result;
        }

        return ltrim($result, '0') ?: '0';
    }


    private static function decimalToHex(string $decimal): string
    {
        $decimal = ltrim($decimal, '0') ?: '0';
        if ('0' === $decimal) {
            return '0';
        }
        $hex = '';
        while ('0' !== $decimal) {
            [$quotient, $remainder] = self::divModSmall($decimal, 16);
            $hex = '0123456789abcdef'[$remainder].$hex;
            $decimal = $quotient;
        }

        return $hex;
    }

    /** @return array{0: string, 1: int} */
    private static function divModSmall(string $decimal, int $divisor): array
    {
        $quotient = '';
        $remainder = 0;
        $len = strlen($decimal);
        for ($i = 0; $i < $len; ++$i) {
            $remainder = $remainder * 10 + (ord($decimal[$i]) - 48);
            $quotient .= (string) intdiv($remainder, $divisor);
            $remainder %= $divisor;
        }

        return [ltrim($quotient, '0') ?: '0', $remainder];
    }

    /** @return array{0: string, 1: string} */
    private static function divModMagnitude(string $dividend, string $divisor): array
    {
        $dividend = ltrim($dividend, '0') ?: '0';
        $divisor = ltrim($divisor, '0') ?: '0';
        if ('0' === $divisor) {
            throw new \DivisionByZeroError('Division by zero');
        }
        if ('0' === $dividend) {
            return ['0', '0'];
        }
        if (self::cmpMagnitude($dividend, $divisor) < 0) {
            return ['0', $dividend];
        }
        $quotient = '';
        $remainder = '0';
        $len = strlen($dividend);
        for ($i = 0; $i < $len; ++$i) {
            $remainder = ltrim($remainder.$dividend[$i], '0') ?: '0';
            $digit = 0;
            for ($d = 9; $d >= 1; --$d) {
                $prod = self::mulSingleDigit($divisor, $d);
                if (self::cmpMagnitude($prod, $remainder) <= 0) {
                    $digit = $d;
                    $remainder = self::subMagnitude($remainder, $prod);
                    break;
                }
            }
            $quotient .= (string) $digit;
        }

        return [ltrim($quotient, '0') ?: '0', $remainder];
    }

    private static function bitwiseOp(string $left, string $right, string $op): string
    {
        $a = self::normalizeSignedDecimal($left);
        $b = self::normalizeSignedDecimal($right);
        $sa = self::splitSign($a);
        $sb = self::splitSign($b);
        $width = max(self::bitLengthMagnitude($sa['mag']), self::bitLengthMagnitude($sb['mag']), 1) + 2;
        $bitsLeft = self::toTwosComplementBits($a, $width);
        $bitsRight = self::toTwosComplementBits($b, $width);
        $out = '';
        for ($i = 0; $i < $width; ++$i) {
            $x = '1' === $bitsLeft[$i];
            $y = '1' === $bitsRight[$i];
            $bit = match ($op) {
                'and' => $x && $y,
                'or' => $x || $y,
                'xor' => $x !== $y,
                default => throw new \LogicException('unknown bitwise op'),
            };
            $out .= $bit ? '1' : '0';
        }

        return self::fromTwosComplementBits($out);
    }

    private static function bitLengthMagnitude(string $mag): int
    {
        $mag = ltrim($mag, '0') ?: '0';
        if ('0' === $mag) {
            return 0;
        }
        $bits = 0;
        $n = $mag;
        while ('0' !== $n) {
            [$n] = self::divModSmall($n, 2);
            ++$bits;
        }

        return $bits;
    }

    private static function toTwosComplementBits(string $signed, int $width): string
    {
        $normalized = self::normalizeSignedDecimal($signed);
        $parts = self::splitSign($normalized);
        if (!$parts['neg']) {
            return self::padBits(self::magnitudeToBits($parts['mag']), $width, '0');
        }
        // two's complement: 2^width - |n|
        $power = self::pow('2', $width);
        $tc = self::sub($power, $parts['mag']);

        return self::padBits(self::magnitudeToBits(self::splitSign($tc)['mag']), $width, '0');
    }

    private static function fromTwosComplementBits(string $bits): string
    {
        $width = strlen($bits);
        if (0 === $width) {
            return '0';
        }
        if ('0' === $bits[0]) {
            return self::normalizeSignedDecimal(self::bitsToMagnitude($bits));
        }
        // negative: value = bits_as_unsigned - 2^width
        $unsigned = self::bitsToMagnitude($bits);
        $power = self::pow('2', $width);

        return self::sub($unsigned, $power);
    }

    private static function magnitudeToBits(string $mag): string
    {
        $mag = ltrim($mag, '0') ?: '0';
        if ('0' === $mag) {
            return '0';
        }
        $bits = '';
        $n = $mag;
        while ('0' !== $n) {
            [$n, $rem] = self::divModSmall($n, 2);
            $bits = (string) $rem.$bits;
        }

        return $bits;
    }

    private static function bitsToMagnitude(string $bits): string
    {
        $acc = '0';
        $len = strlen($bits);
        for ($i = 0; $i < $len; ++$i) {
            $acc = self::mulMagnitude($acc, '2');
            if ('1' === $bits[$i]) {
                $acc = self::addMagnitude($acc, '1');
            }
        }

        return $acc;
    }

    private static function padBits(string $bits, int $width, string $pad): string
    {
        $len = strlen($bits);
        if ($len >= $width) {
            return substr($bits, -$width);
        }

        return str_repeat($pad, $width - $len).$bits;
    }

    private static function signedIntFromBits(string $bits): int
    {
        $width = strlen($bits);
        if (0 === $width) {
            return 0;
        }
        $negative = '1' === $bits[0];
        if (!$negative) {
            $mag = self::bitsToMagnitude($bits);
            if (self::cmp($mag, (string) \PHP_INT_MAX) > 0) {
                return \PHP_INT_MAX;
            }

            return (int) $mag;
        }
        $unsigned = self::bitsToMagnitude($bits);
        $power = self::pow('2', $width);
        $signed = self::sub($unsigned, $power);
        if (self::cmp($signed, (string) \PHP_INT_MIN) < 0) {
            return \PHP_INT_MIN;
        }

        return (int) $signed;
    }
}
