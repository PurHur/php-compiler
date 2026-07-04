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

    public function toInt(): int
    {
        return 0 === $this->hi ? $this->lo : ($this->lo | ($this->hi << 32));
    }

    /** php-src php_random_engine_common.c — u64 as 8-byte little-endian string. */
    public function toBytes(): string
    {
        return \pack('P', $this->toInt());
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

    public static function mul32(self $a, int $multiplier): self
    {
        $multiplier &= 0xFFFFFFFF;
        $a0 = $a->lo & 0xFFFF;
        $a1 = ($a->lo >> 16) & 0xFFFF;
        $b0 = $multiplier & 0xFFFF;
        $b1 = ($multiplier >> 16) & 0xFFFF;
        $p00 = $a0 * $b0;
        $p01 = $a0 * $b1;
        $p10 = $a1 * $b0;
        $p11 = $a1 * $b1;
        $mid = (($p00 >> 16) & 0xFFFF) + ($p01 & 0xFFFF) + ($p10 & 0xFFFF);
        $lo = (($p00 & 0xFFFF) | (($mid & 0xFFFF) << 16)) & 0xFFFFFFFF;
        $carry = (($mid >> 16) & 0xFFFF) + (($p01 >> 16) & 0xFFFF) + (($p10 >> 16) & 0xFFFF) + $p11;
        $hi = (($a->hi * $multiplier) + $carry) & 0xFFFFFFFF;

        return new self($hi, $lo);
    }

    public static function mul64(self $a, self $b): self
    {
        $a0 = $a->lo & 0xFFFFFFFF;
        $a1 = $a->hi & 0xFFFFFFFF;
        $b0 = $b->lo & 0xFFFFFFFF;
        $b1 = $b->hi & 0xFFFFFFFF;
        $p00 = $a0 * $b0;
        $p01 = $a0 * $b1;
        $p10 = $a1 * $b0;
        $lo = $p00 & 0xFFFFFFFF;
        $mid = (($p00 >> 32) & 0xFFFFFFFF) + ($p01 & 0xFFFFFFFF) + ($p10 & 0xFFFFFFFF);
        $hi = (($p01 >> 32) & 0xFFFFFFFF) + (($p10 >> 32) & 0xFFFFFFFF) + (($mid >> 32) & 0xFFFFFFFF);

        return new self($hi & 0xFFFFFFFF, $lo);
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
        if (0 === $bits) {
            return new self($this->hi, $this->lo);
        }
        if ($bits >= 32) {
            return new self(0, $this->hi >> ($bits - 32));
        }

        return new self(
            $this->hi >> $bits,
            (($this->lo >> $bits) | ($this->hi << (32 - $bits))) & 0xFFFFFFFF
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
