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
        $loLo = $a->lo->lo + $b->lo->lo;
        $carryLo = intdiv($loLo, 0x100000000);
        $loHi = $a->lo->hi + $b->lo->hi + $carryLo;
        $carryHi = intdiv($loHi, 0x100000000);
        $lo = new RandomU64($loHi & 0xFFFFFFFF, $loLo & 0xFFFFFFFF);

        $hiLo = $a->hi->lo + $b->hi->lo + $carryHi;
        $carryTop = intdiv($hiLo, 0x100000000);
        $hi = new RandomU64(($a->hi->hi + $b->hi->hi + $carryTop) & 0xFFFFFFFF, $hiLo & 0xFFFFFFFF);

        return new self($hi, $lo);
    }

    public static function multiply(self $a, self $b): self
    {
        $lo = RandomU64::mul64($a->lo, $b->lo);
        $hi = RandomU64::add(
            RandomU64::add(RandomU64::mul64Hi($a->lo, $b->lo), RandomU64::mul64($a->lo, $b->hi)),
            RandomU64::mul64($a->hi, $b->lo)
        );

        return new self($hi, $lo);
    }

    public static function pcgRotr64(self $num): RandomU64
    {
        $v = RandomU64::xor($num->hi, $num->lo);
        $s = $num->hi->shiftRight(58)->lo & 0x3F;
        if (0 === $s) {
            return $v;
        }

        return RandomU64::or($v->shiftRight($s), $v->shiftLeft(((-$s) & 63)));
    }
}
