<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmStreamArg;

/**
 * GMP integer semantics in PHP (php-src ext/gmp/gmp.c; issue #3341).
 *
 * Phase 1: decimal/hex init, add/sub/mul/cmp/strval — no runtime/*.c growth.
 */
final class VmGmp
{
    public const CLASS_LC = 'gmp';

    public const PROP_VALUE = 'num';

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
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return (string) $resolved->toInt();
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            return '0';
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
        if (10 === $base) {
            if (!preg_match('/^[+-]?[0-9]+$/', $trimmed)) {
                throw new \ValueError(\sprintf(
                    '%s(): Argument #%d ($%s) is not a valid GMP base %d integer',
                    $function,
                    $index + 1,
                    $label,
                    $base
                ));
            }

            return self::normalizeSignedDecimal($trimmed);
        }
        if (16 === $base) {
            if (!preg_match('/^[+-]?[0-9A-Fa-f]+$/', $trimmed)) {
                throw new \ValueError(\sprintf(
                    '%s(): Argument #%d ($%s) is not a valid GMP base %d integer',
                    $function,
                    $index + 1,
                    $label,
                    $base
                ));
            }
            $negative = str_starts_with($trimmed, '-');
            $hex = ltrim($negative ? substr($trimmed, 1) : ($trimmed[0] === '+' ? substr($trimmed, 1) : $trimmed), '0');
            if ('' === $hex) {
                return '0';
            }
            $decimal = self::hexToDecimal($hex);
            if ($negative && '0' !== $decimal) {
                $decimal = '-'.$decimal;
            }

            return self::normalizeSignedDecimal($decimal);
        }

        throw new \ValueError(\sprintf(
            '%s(): Argument #%d ($%s) uses unsupported base %d in this compiler build',
            $function,
            $index + 1,
            $label,
            $base
        ));
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

    private static function hexToDecimal(string $hex): string
    {
        $hex = strtolower($hex);
        $acc = '0';
        for ($i = 0, $len = strlen($hex); $i < $len; ++$i) {
            $acc = self::mulMagnitude($acc, '16');
            $digit = ord($hex[$i]);
            $value = ($digit >= 97) ? ($digit - 87) : ($digit - 48);
            $acc = self::addMagnitude($acc, (string) $value);
        }

        return $acc;
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
}
