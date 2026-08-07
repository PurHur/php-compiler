<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

/** Unsigned 64-bit integer as two 32-bit limbs (php-src uint64_t). */
final class RandomU64
{
    public function __construct(
        public int $hi = 0,
        public int $lo = 0,
    ) {
        $this->hi &= 0xFFFFFFFF;
        $this->lo &= 0xFFFFFFFF;
    }

    public static function from32(int $lo): self
    {
        return new self(0, $lo);
    }

    public static function fromHex64(int $hi, int $lo): self
    {
        return new self($hi & 0xFFFFFFFF, $lo & 0xFFFFFFFF);
    }

    public static function fromParts(int $hi, int $lo): self
    {
        return self::fromHex64($hi, $lo);
    }

    /** Split a non-negative PHP int into unsigned 32-bit limbs (values up to 2^63-1). */
    public static function fromUint64(int $value): self
    {
        if ($value < 0) {
            throw new \LogicException('fromUint64 requires a non-negative value');
        }

        return new self(($value >> 32) & 0xFFFFFFFF, $value & 0xFFFFFFFF);
    }

    public function toInt(): int
    {
        return 0 === $this->hi ? $this->lo : ($this->lo | ($this->hi << 32));
    }

    /** Pack u64 little-endian (php-src php_random_engine_common.c). */
    public function toBytes(): string
    {
        return \pack('VV', $this->lo, $this->hi);
    }

    public static function add(self $a, self $b): self
    {
        $lo = ($a->lo + $b->lo) & 0xFFFFFFFF;
        $carry = ($lo < $a->lo) ? 1 : 0;

        return new self(($a->hi + $b->hi + $carry) & 0xFFFFFFFF, $lo);
    }

    public static function xor(self $a, self $b): self
    {
        return new self($a->hi ^ $b->hi, $a->lo ^ $b->lo);
    }

    public static function or(self $a, self $b): self
    {
        return new self($a->hi | $b->hi, $a->lo | $b->lo);
    }

    public static function and(self $a, self $b): self
    {
        return new self($a->hi & $b->hi, $a->lo & $b->lo);
    }

    /** Unsigned compare: -1 if $a < $b, 0 if equal, 1 if $a > $b. */
    public static function compare(self $a, self $b): int
    {
        if ($a->hi !== $b->hi) {
            return $a->hi < $b->hi ? -1 : 1;
        }
        if ($a->lo === $b->lo) {
            return 0;
        }

        return $a->lo < $b->lo ? -1 : 1;
    }

    /** $value mod $modulus for positive modulus fitting in int (php-src uint64_t %). */
    public static function modSmall(self $value, int $modulus): int
    {
        if ($modulus <= 0) {
            throw new \LogicException('modulus must be positive');
        }
        if (0 === $value->hi) {
            return $value->lo % $modulus;
        }
        // ((hi % m) * (2^32 % m) + (lo % m)) % m — mul without overflowing PHP int (#28526).
        $hiMod = $value->hi % $modulus;
        $loMod = $value->lo % $modulus;
        $two32mod = 4294967296 % $modulus;

        return self::addMod(self::mulMod($hiMod, $two32mod, $modulus), $loMod, $modulus);
    }

    /** ($a * $b) % $m for non-negative operands (avoids float promotion on large products). */
    private static function mulMod(int $a, int $b, int $m): int
    {
        $a %= $m;
        $b %= $m;
        if (0 === $a || 0 === $b) {
            return 0;
        }
        if ($b <= intdiv(\PHP_INT_MAX, $a)) {
            return ($a * $b) % $m;
        }
        $result = 0;
        while ($b > 0) {
            if (0 !== ($b & 1)) {
                $result = self::addMod($result, $a, $m);
            }
            $a = self::addMod($a, $a, $m);
            $b >>= 1;
        }

        return $result;
    }

    private static function addMod(int $a, int $b, int $m): int
    {
        $a %= $m;
        $b %= $m;
        // Both in [0, m); avoid a+b promoting to float when 2m > PHP_INT_MAX.
        if ($a >= $m - $b) {
            return $a - ($m - $b);
        }

        return $a + $b;
    }

    public function upper53UnitFloat(): float
    {
        $shifted = $this->shiftRight(11);
        $divisor = 9007199254740992.0;
        if (0 === $shifted->hi) {
            return $shifted->lo / $divisor;
        }

        return ($shifted->hi * 4294967296.0 + $shifted->lo) / $divisor;
    }

    public static function mul32(self $a, int $multiplier): self
    {
        return self::mul64($a, self::from32($multiplier & 0xFFFFFFFF));
    }

    /** @return array{0: int, 1: int} upper and lower 32-bit limbs of unsigned product */
    private static function mul32x32(int $x, int $y): array
    {
        $x &= 0xFFFFFFFF;
        $y &= 0xFFFFFFFF;
        $xl = [$x & 0xFFFF, ($x >> 16) & 0xFFFF];
        $yl = [$y & 0xFFFF, ($y >> 16) & 0xFFFF];
        $r = [0, 0, 0, 0];
        for ($i = 0; $i < 2; ++$i) {
            for ($j = 0; $j < 2; ++$j) {
                $r[$i + $j] += $xl[$i] * $yl[$j];
            }
        }
        for ($k = 0; $k < 3; ++$k) {
            $r[$k + 1] += $r[$k] >> 16;
            $r[$k] &= 0xFFFF;
        }
        $lo = $r[0] | ($r[1] << 16);
        $hi = $r[2] | ($r[3] << 16);

        return [$hi & 0xFFFFFFFF, $lo & 0xFFFFFFFF];
    }

    public static function mul64(self $a, self $b): self
    {
        [$p00_hi, $p00_lo] = self::mul32x32($a->lo, $b->lo);
        [, $p01_lo] = self::mul32x32($a->lo, $b->hi);
        [, $p10_lo] = self::mul32x32($a->hi, $b->lo);

        $mid = ($p00_hi + $p01_lo + $p10_lo) & 0xFFFFFFFF;

        return new self($mid, $p00_lo);
    }

    /** Upper 64 bits of unsigned 64x64 product (libgcc umul_ppmm high half). */
    public static function mul64Hi(self $a, self $b): self
    {
        $u0 = $a->lo;
        $u1 = $a->hi;
        $v0 = $b->lo;
        $v1 = $b->hi;
        [$p00_hi, ] = self::mul32x32($u0, $v0);
        [$u1v0_hi, $u1v0_lo] = self::mul32x32($u1, $v0);
        [$u0v1_hi, $u0v1_lo] = self::mul32x32($u0, $v1);
        [$u1v1_hi, $u1v1_lo] = self::mul32x32($u1, $v1);

        $t = $u1v0_lo + $p00_hi;
        $t_hi = $u1v0_hi + intdiv($t, 0x100000000);
        $t_lo = $t & 0xFFFFFFFF;

        $inner = $u0v1_lo + $t_lo;
        $part2 = $u0v1_hi + intdiv($inner, 0x100000000);

        $hi = ($u1v1_lo + $part2 + $t_hi) & 0xFFFFFFFF;
        $extra = intdiv($u1v1_lo + $part2 + $t_hi, 0x100000000);

        return new self(($u1v1_hi + $extra) & 0xFFFFFFFF, $hi);
    }

    public function shiftLeft(int $bits): self
    {
        $bits &= 63;
        if (0 === $bits) {
            return new self($this->hi, $this->lo);
        }
        if ($bits >= 32) {
            return new self($this->lo << ($bits - 32), 0);
        }

        return new self(
            (($this->hi << $bits) | ($this->lo >> (32 - $bits))) & 0xFFFFFFFF,
            ($this->lo << $bits) & 0xFFFFFFFF
        );
    }

    public function shiftRight(int $bits): self
    {
        $bits &= 63;
        $hi = $this->hi & 0xFFFFFFFF;
        $lo = $this->lo & 0xFFFFFFFF;
        if (0 === $bits) {
            return new self($hi, $lo);
        }
        if ($bits >= 32) {
            return new self(0, $hi >> ($bits - 32));
        }

        $hiLowBits = $hi & ((1 << $bits) - 1);

        return new self(
            $hi >> $bits,
            (($lo >> $bits) | ($hiLowBits << (32 - $bits))) & 0xFFFFFFFF
        );
    }

    public static function rotl(self $x, int $k): self
    {
        return self::or($x->shiftLeft($k & 63), $x->shiftRight(64 - ($k & 63)));
    }

    public function lowBitSet(int $bit): bool
    {
        $bit &= 63;

        return $bit < 32
            ? 0 !== ($this->lo & (1 << $bit))
            : 0 !== ($this->hi & (1 << ($bit - 32)));
    }
}
