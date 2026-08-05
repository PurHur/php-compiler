<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * sscanf() for compiled JIT/AOT modules (#9134, #12467 php-in-PHP).
 *
 * SSOT: {@see VmSscanf} (php-src ext/standard/sscanf.c).
 * NestedJIT note (#27663 / peer #26862): do **not** call \round() — MathRound skips
 * under NestedJIT so thin AOT fscanf/vfscanf fails with unresolved `phpc_round`.
 */
final class SscanfJitHelper
{
    private const TAG_NULL = 0;

    private const TAG_LONG = 1;

    private const TAG_DOUBLE = 2;

    private const TAG_BOOL = 3;

    private const TAG_STRING = 4;

    public static function parseToArray(string $input, string $format): ?HashTable
    {
        return VmSscanf::parseToArray($input, $format);
    }

    /**
     * By-ref assignment path: returns meta blob `assigned(q) + consumed(q) + encoded values`.
     */
    public static function parseAssignMeta(string $input, string $format, int $outCount): string
    {
        if ($outCount <= 0) {
            return self::packMeta(0, 0, '');
        }

        $outVars = [];
        for ($i = 0; $i < $outCount; ++$i) {
            $outVars[] = new Variable();
        }

        [$assigned, $consumed, $stored] = VmSscanf::parseWithConsumed($input, $format, $outVars);
        $payload = '';
        for ($i = 0; $i < $stored; ++$i) {
            $payload .= self::encodeVariable($outVars[$i]->resolveIndirect());
        }

        return self::packMeta($assigned, $consumed, $payload);
    }

    private static function packMeta(int $assigned, int $consumed, string $payload): string
    {
        return self::packInt64Le($assigned).self::packInt64Le($consumed).$payload;
    }

    private static function packInt64Le(int $value): string
    {
        $bytes = '';
        for ($i = 0; $i < 8; ++$i) {
            $bytes .= \chr($value & 0xff);
            $value >>= 8;
        }

        return $bytes;
    }

    private static function packDoubleLe(float $value): string
    {
        [$hi, $lo] = self::float64ToBits($value);

        return self::u32Le($lo).self::u32Le($hi);
    }

    private static function u32Le(int $bits): string
    {
        return \chr($bits & 0xFF)
            .\chr(($bits >> 8) & 0xFF)
            .\chr(($bits >> 16) & 0xFF)
            .\chr(($bits >> 24) & 0xFF);
    }

    /** @return array{0: int, 1: int} IEEE754 high/low limbs for nested JIT encode (#9134). */
    private static function float64ToBits(float $value): array
    {
        if (\is_nan($value)) {
            return [0x7FF80000, 0x00000000];
        }
        if ($value === INF) {
            return [0x7FF00000, 0x00000000];
        }
        if ($value === -INF) {
            return [0xFFF00000, 0x00000000];
        }
        if (0.0 == $value) {
            return 0.0 !== \atan2(0.0, $value)
                ? [0x80000000, 0x00000000]
                : [0x00000000, 0x00000000];
        }

        $sign = $value < 0 ? 1 : 0;
        $abs = \abs($value);
        [$mantissa, $exponent] = self::frexpDecompose($abs);
        $exp = $exponent - 1 + 1023;
        // Half-up without round builtin (#27663 / Ieee754 #26862).
        $scaled = ($mantissa - 0.5) * 2.0 * 4503599627370496.0;
        $fraction = (int) ($scaled + 0.5);
        if ($fraction >= 4503599627370496) {
            $fraction = 0;
            ++$exp;
        }

        $hi = ($sign << 31) | (($exp & 0x7FF) << 20) | (int) (($fraction >> 32) & 0xFFFFF);
        $lo = $fraction & 0xFFFFFFFF;

        return [$hi, $lo];
    }

    /** @return array{0: float, 1: int} */
    private static function frexpDecompose(float $abs): array
    {
        if (0.0 === $abs) {
            return [0.0, 0];
        }
        $exp = 0;
        $mantissa = $abs;
        while ($mantissa >= 1.0) {
            $mantissa /= 2.0;
            ++$exp;
        }
        while ($mantissa > 0.0 && $mantissa < 0.5) {
            $mantissa *= 2.0;
            --$exp;
        }

        return [$mantissa, $exp];
    }

    /**
     * @param list<Variable> $outVars assigned prefix only
     */
    public static function packMetaFromVariables(int $assigned, int $consumed, array $outVars): string
    {
        $payload = '';
        for ($i = 0; $i < $assigned; ++$i) {
            $payload .= self::encodeVariable($outVars[$i]->resolveIndirect());
        }

        return self::packMeta($assigned, $consumed, $payload);
    }

    private static function encodeVariable(Variable $value): string
    {
        switch ($value->type) {
            case Variable::TYPE_NULL:
                return \chr(self::TAG_NULL);
            case Variable::TYPE_INTEGER:
                return \chr(self::TAG_LONG).self::packInt64Le($value->toInt());
            case Variable::TYPE_FLOAT:
                return \chr(self::TAG_DOUBLE).self::packDoubleLe($value->toFloat());
            case Variable::TYPE_BOOLEAN:
                return \chr(self::TAG_BOOL).self::packInt64Le($value->toBool() ? 1 : 0);
            case Variable::TYPE_STRING:
                $s = $value->toString();

                return \chr(self::TAG_STRING).self::packInt64Le(\strlen($s)).$s;
            default:
                return \chr(self::TAG_LONG).self::packInt64Le($value->toInt());
        }
    }
}
