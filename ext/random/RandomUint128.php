<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

/** 128-bit unsigned integer (php-src php_random_uint128.h). */
final class RandomUint128
{
    public function __construct(
        public RandomU64 $hi,
        public RandomU64 $lo,
    ) {
    }

    public static function constant(int $hiHi, int $hiLo, int $loHi, int $loLo): self
    {
        return new self(RandomU64::fromHex64($hiHi, $hiLo), RandomU64::fromHex64($loHi, $loLo));
    }

    public static function add(self $a, self $b): self
    {
        $lo = RandomU64::add($a->lo, $b->lo);
        $carry = ($lo->lo < $a->lo->lo) || ($lo->hi < $a->lo->hi && $lo->lo >= $a->lo->lo);
        $hi = RandomU64::add($a->hi, $b->hi);
        if ($carry) {
            $hi = RandomU64::add($hi, RandomU64::from32(1));
        }

        return new self($hi, $lo);
    }

    public static function multiply(self $a, self $b): self
    {
        $x0 = $a->lo->lo & 0xFFFFFFFF;
        $x1 = ($a->lo->lo >> 32) & 0xFFFFFFFF;
        $y0 = $b->lo->lo & 0xFFFFFFFF;
        $y1 = ($b->lo->lo >> 32) & 0xFFFFFFFF;
        $z0 = (((($x1 * $y0) & 0xFFFFFFFF) + ((($x0 * $y0) >> 32) & 0xFFFFFFFF)) & 0xFFFFFFFF) + ($x0 * $y1);
        $hi = RandomU64::add(
            RandomU64::add(RandomU64::mul32($a->hi, $b->lo->lo), RandomU64::mul32($a->lo, $b->hi->lo)),
            RandomU64::fromParts(
                ($x1 * $y1 + ((($x1 * $y0 + (($x0 * $y0) >> 32)) >> 32) & 0xFFFFFFFF) + ($z0 >> 32)) & 0xFFFFFFFF,
                0
            )
        );
        $lo = RandomU64::fromParts(0, ($a->lo->lo * $b->lo->lo) & 0xFFFFFFFF);

        return new self($hi, $lo);
    }

    public static function pcgRotr64(self $num): int
    {
        return self::pcgRotr64U64($num)->toInt() & ~0;
    }

    public static function pcgRotr64Bytes(self $num): string
    {
        return self::pcgRotr64U64($num)->toBytes();
    }

    private static function pcgRotr64U64(self $num): RandomU64
    {
        $v = RandomU64::xor($num->hi, $num->lo);
        $s = $num->hi->lo >> 26;
        if (0 === $s) {
            return $v;
        }

        return RandomU64::or($v->shiftRight($s), $v->shiftLeft(((-$s) & 63)));
    }
}
