<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure PHP XXH3_64bits / XXH3_128bits (xxHash v0.8.1; php-src ext/hash/hash_xxhash.c).
 *
 * No libxxhash FFI — self-host M5 path (#12209, #5165).
 */
final class VmHashXxhPure
{
    private const PRIME64_1 = 0x9E3779B185EBCA87; // use prime64_1() — literal overflows PHP int
    private const PRIME64_2 = 0xC2B2AE3D27D4EB4F; // use prime64_2()
    private const PRIME64_3 = 0x165667B19E3779F9;
    private const PRIME64_4 = 0x85EBCA77C2B2AE63; // use prime64_4()
    private const PRIME64_5 = 0x27D4EB2F165667C5;
    private const PRIME32_1 = 0x9E3779B1;
    private const PRIME32_2 = 0x85EBCA77;
    private const PRIME32_3 = 0xC2B2AE3D;
    private const PRIME_MX1 = 0x165667919E3779F9;
    private const PRIME_MX2 = 0x9FB21C651E98DF25; // use primeMx2()
    private const MIDSIZE_MAX = 240;
    private const STRIPE_LEN = 64;
    private const SECRET_CONSUME_RATE = 8;
    private const SECRET_MERGEACCS_START = 11;
    private const SECRET_SIZE_MIN = 136;
    private const MIDSIZE_STARTOFFSET = 3;
    private const MIDSIZE_LASTOFFSET = 17;
    private const ACC_NB = 8;

    private const KSECRET = "\xb8\xfe\x6c\x39\x23\xa4\x4b\xbe\x7c\x01\x81\x2c\xf7\x21\xad\x1c"
        ."\xde\xd4\x6d\xe9\x83\x90\x97\xdb\x72\x40\xa4\xa4\xb7\xb3\x67\x1f"
        ."\xcb\x79\xe6\x4e\xcc\xc0\xe5\x78\x82\x5a\xd0\x7d\xcc\xff\x72\x21"
        ."\xb8\x08\x46\x74\xf7\x43\x24\x8e\xe0\x35\x90\xe6\x81\x3a\x26\x4c"
        ."\x3c\x28\x52\xbb\x91\xc3\x00\xcb\x88\xd0\x65\x8b\x1b\x53\x2e\xa3"
        ."\x71\x64\x48\x97\xa2\x0d\xf9\x4e\x38\x19\xef\x46\xa9\xde\xac\xd8"
        ."\xa8\xfa\x76\x3f\xe3\x9c\x34\x3f\xf9\xdc\xbb\xc7\xc7\x0b\x4f\x1d"
        ."\x8a\x51\xe0\x4b\xcd\xb4\x59\x31\xc8\x9f\x7e\xc9\xd9\x78\x73\x64"
        ."\xea\xc5\xac\x83\x34\xd3\xeb\xc3\xc5\x81\xa0\xff\xfa\x13\x63\xeb"
        ."\x17\x0d\xdd\x51\xb7\xf0\xda\x49\xd3\x16\x55\x26\x29\xd4\x68\x9e"
        ."\x2b\x16\xbe\x58\x7d\x47\xa1\xfc\x8f\xf8\xb8\xd1\x7a\xd0\x31\xce"
        ."\x45\xcb\x3a\x8f\x95\x16\x04\x28\xaf\xd7\xfb\xca\xbb\x4b\x40\x7e";

    private static bool $inDigest = false;

    private static function prime64_1(): int
    {
        return self::compose64(0x85EBCA87, 0x9E3779B1);
    }

    private static function prime64_2(): int
    {
        return self::compose64(0x27D4EB4F, 0xC2B2AE3D);
    }

    private static function prime64_4(): int
    {
        return self::compose64(0xC2B2AE63, 0x85EBCA77);
    }

    private static function primeMx2(): int
    {
        return self::compose64(0x1E98DF25, 0x9FB21C65);
    }

    public static function available(): bool
    {
        return true;
    }

    /** @return list<int>|null */
    public static function xxh3DigestBytes(string $data): ?array
    {
        return self::u64DigestBytes(self::xxh3_64($data));
    }

    /** @return list<int>|null */
    public static function xxh128DigestBytes(string $data): ?array
    {
        [$lo, $hi] = self::xxh3_128($data);

        return array_merge(self::u64DigestBytes($lo), self::u64DigestBytes($hi));
    }

    private static function xxh3_64(string $data): int
    {
        if (!self::$inDigest && \function_exists('hash') && \in_array('xxh3', \hash_algos(), true)) {
            self::$inDigest = true;
            try {
                $raw = \hash('xxh3', $data, true);
                if (false !== $raw && 8 === \strlen($raw)) {
                    return unpack('J', $raw)[1];
                }
            } finally {
                self::$inDigest = false;
            }
        }
        $ln = \strlen($data);
        $secret = self::KSECRET;
        if ($ln <= 16) {
            return self::len0to16_64($data, $secret, 0);
        }
        if ($ln <= 128) {
            return self::len17to128_64($data, $secret, 0);
        }
        if ($ln <= self::MIDSIZE_MAX) {
            return self::len129to240_64($data, $secret, 0);
        }

        return self::hashLong64($data, $secret);
    }

    /** @return array{0:int,1:int} */
    private static function xxh3_128(string $data): array
    {
        if (!self::$inDigest && \function_exists('hash') && \in_array('xxh128', \hash_algos(), true)) {
            self::$inDigest = true;
            try {
                $raw = \hash('xxh128', $data, true);
                if (false !== $raw && 16 === \strlen($raw)) {
                    $lo = unpack('J', substr($raw, 0, 8))[1];
                    $hi = unpack('J', substr($raw, 8, 8))[1];

                    return [$lo, $hi];
                }
            } finally {
                self::$inDigest = false;
            }
        }
        $ln = \strlen($data);
        $secret = self::KSECRET;
        if ($ln <= 16) {
            return self::len0to16_128($data, $secret, 0);
        }
        if ($ln <= 128) {
            return self::len17to128_128($data, $secret, 0);
        }
        if ($ln <= self::MIDSIZE_MAX) {
            return self::len129to240_128($data, $secret, 0);
        }

        return self::hashLong128($data, $secret);
    }

    private static function u64(int|float $x): int
    {
        if (is_float($x)) {
            $x = (int) $x;
        }

        return unpack('J', pack('J', $x))[1];
    }

    private static function compose64(int $lo, int $hi): int
    {
        return unpack('P', pack('VV', self::u32($lo), self::u32($hi)))[1];
    }

    /** @return array{0:int,1:int} */
    private static function mul32(int $x, int $y): array
    {
        $x = self::u32($x);
        $y = self::u32($y);
        $x0 = $x & 0xFFFF;
        $x1 = ($x >> 16) & 0xFFFF;
        $y0 = $y & 0xFFFF;
        $y1 = ($y >> 16) & 0xFFFF;
        $p00 = $x0 * $y0;
        $p01 = $x0 * $y1;
        $p10 = $x1 * $y0;
        $p11 = $x1 * $y1;
        $mid = ($p01 & 0xFFFF) + ($p10 & 0xFFFF) + ($p00 >> 16);
        $hi = ($p01 >> 16) + ($p10 >> 16) + ($mid >> 16) + $p11;
        $lo = (($mid & 0xFFFF) << 16) | ($p00 & 0xFFFF);

        return [self::u32($lo), self::u32($hi)];
    }

    private static function u64add(int|float ...$parts): int
    {
        $accLo = 0;
        $accHi = 0;
        foreach ($parts as $part) {
            $part = self::u64($part);
            $pLo = self::u32($part);
            $pHi = ($part >> 32) & 0xFFFFFFFF;
            $sumLo = $accLo + $pLo;
            $carry = ($sumLo >> 32) & 1;
            $accLo = self::u32($sumLo);
            $accHi = self::u32($accHi + $pHi + $carry);
        }

        return self::compose64($accLo, $accHi);
    }

    private static function u64or(int|float $a, int|float $b): int
    {
        $a = self::u64($a);
        $b = self::u64($b);

        return self::compose64(
            self::u32($a) | self::u32($b),
            (($a >> 32) & 0xFFFFFFFF) | (($b >> 32) & 0xFFFFFFFF),
        );
    }

    private static function u64xor(int|float $a, int|float $b): int
    {
        $a = self::u64($a);
        $b = self::u64($b);

        return self::compose64(
            self::u32($a) ^ self::u32($b),
            (($a >> 32) & 0xFFFFFFFF) ^ (($b >> 32) & 0xFFFFFFFF),
        );
    }

    private static function u64sub(int|float $a, int|float $b): int
    {
        $a = self::u64($a);
        $b = self::u64($b);
        $aLo = self::u32($a);
        $aHi = ($a >> 32) & 0xFFFFFFFF;
        $bLo = self::u32($b);
        $bHi = ($b >> 32) & 0xFFFFFFFF;
        $borrow = ($aLo < $bLo) ? 1 : 0;
        $diffLo = $aLo - $bLo;
        $diffHi = $aHi - $bHi - $borrow;

        return self::compose64($diffLo & 0xFFFFFFFF, $diffHi & 0xFFFFFFFF);
    }

    private static function u64shl(int|float $x, int $r): int
    {
        $r &= 63;
        if (0 === $r) {
            return self::u64($x);
        }
        $x = self::u64($x);
        $lo = self::u32($x);
        $hi = ($x >> 32) & 0xFFFFFFFF;
        if ($r < 32) {
            $newHi = self::u32(($hi << $r) | ($lo >> (32 - $r)));
            $newLo = self::u32($lo << $r);
        } else {
            $newHi = self::u32($lo << ($r - 32));
            $newLo = 0;
        }

        return self::compose64($newLo, $newHi);
    }

    private static function u64shr(int|float $x, int $r): int
    {
        $r &= 63;
        if (0 === $r) {
            return self::u64($x);
        }
        $x = self::u64($x);
        $lo = self::u32($x);
        $hi = ($x >> 32) & 0xFFFFFFFF;
        if ($r < 32) {
            $newLo = self::u32(($lo >> $r) | ($hi << (32 - $r)));
            $newHi = self::u32($hi >> $r);
        } else {
            $newLo = self::u32($hi >> ($r - 32));
            $newHi = 0;
        }

        return self::compose64($newLo, $newHi);
    }

    private static function u32(int $x): int
    {
        return $x & 0xFFFFFFFF;
    }

    private static function readLe32(string $data, int $off): int
    {
        return unpack('V', substr($data, $off, 4))[1];
    }

    private static function readLe64(string $data, int $off): int
    {
        return unpack('P', substr($data, $off, 8))[1];
    }

    private static function swap64(int $x): int
    {
        return unpack('J', pack('P', $x))[1];
    }

    private static function swap32(int $x): int
    {
        return unpack('N', pack('V', self::u32($x)))[1];
    }

    private static function rotl64(int $x, int $r): int
    {
        $r &= 63;

        return self::u64or(self::u64shl($x, $r), self::u64shr($x, 64 - $r));
    }

    private static function rotl32(int $x, int $r): int
    {
        $x = self::u32($x);

        return self::u32(($x << $r) | ($x >> (32 - $r)));
    }

    private static function xorshift64(int $v, int $shift): int
    {
        return self::u64xor($v, self::u64shr($v, $shift));
    }

    private static function mult32to64(int $x, int $y): int
    {
        [$lo, $hi] = self::mul32($x, $y);

        return self::compose64($lo, $hi);
    }

    private static function u64mul(int|float $a, int|float $b): int
    {
        [$lo] = self::mult64to128($a, $b);

        return $lo;
    }

    private static function mult32to64Add64(int $lhs, int $rhs, int $acc): int
    {
        return self::u64add(self::mult32to64($lhs, $rhs), $acc);
    }

    /** @return array{0:int,1:int} */
    private static function mult64to128(int|float $lhs, int|float $rhs): array
    {
        $lhs = self::u64($lhs);
        $rhs = self::u64($rhs);
        $loLo = self::mult32to64($lhs & 0xFFFFFFFF, $rhs & 0xFFFFFFFF);
        $hiLo = self::mult32to64(($lhs >> 32) & 0xFFFFFFFF, $rhs & 0xFFFFFFFF);
        $loHi = self::mult32to64($lhs & 0xFFFFFFFF, ($rhs >> 32) & 0xFFFFFFFF);
        $hiHi = self::mult32to64(($lhs >> 32) & 0xFFFFFFFF, ($rhs >> 32) & 0xFFFFFFFF);
        $cross = self::u64add(($loLo >> 32) & 0xFFFFFFFF, $hiLo & 0xFFFFFFFF, $loHi);
        $upper = self::u64add(($hiLo >> 32) & 0xFFFFFFFF, ($cross >> 32) & 0xFFFFFFFF, $hiHi);
        $lower = self::u64or(self::u64shl($cross, 32), $loLo & 0xFFFFFFFF);

        return [$lower, $upper];
    }

    private static function mul128Fold64(int $lhs, int $rhs): int
    {
        [$lo, $hi] = self::mult64to128($lhs, $rhs);

        return self::u64($lo ^ $hi);
    }

    private static function xxh64Avalanche(int $h64): int
    {
        $h64 = self::xorshift64($h64, 33);
        $h64 = self::u64mul($h64, self::prime64_2());
        $h64 = self::xorshift64($h64, 29);
        $h64 = self::u64mul($h64, self::PRIME64_3);

        return self::xorshift64($h64, 32);
    }

    private static function xxh3Avalanche(int $h64): int
    {
        $h64 = self::xorshift64($h64, 37);
        $h64 = self::u64mul($h64, self::PRIME_MX1);

        return self::xorshift64($h64, 32);
    }

    private static function xxh3Rrmxmx(int $h64, int $length): int
    {
        $h64 = self::u64xor($h64, self::u64xor(self::rotl64($h64, 49), self::rotl64($h64, 24)));
        $h64 = self::u64mul($h64, self::primeMx2());
        $h64 = self::u64xor($h64, self::u64add(self::u64shr($h64, 35), $length));
        $h64 = self::u64mul($h64, self::primeMx2());

        return self::xorshift64($h64, 28);
    }

    private static function mix16b(string $inp, string $sec, int $offInp, int $offSec, int $seed = 0): int
    {
        $ilo = self::readLe64($inp, $offInp);
        $ihi = self::readLe64($inp, $offInp + 8);
        $slo = self::readLe64($sec, $offSec);
        $shi = self::readLe64($sec, $offSec + 8);

        return self::mul128Fold64(
            self::u64xor($ilo, self::u64add($slo, $seed)),
            self::u64xor($ihi, self::u64sub($shi, $seed)),
        );
    }

    private static function len1to3_64(string $data, string $secret, int $seed = 0): int
    {
        $ln = \strlen($data);
        $c1 = \ord($data[0]);
        $c2 = \ord($data[$ln >> 1]);
        $c3 = \ord($data[$ln - 1]);
        $combined = ($c1 << 16) | ($c2 << 24) | $c3 | ($ln << 8);
        $bitflip = (self::readLe32($secret, 0) ^ self::readLe32($secret, 4)) + $seed;

        return self::xxh64Avalanche(self::u64xor($combined, $bitflip));
    }

    private static function len4to8_64(string $data, string $secret, int $seed = 0): int
    {
        $ln = \strlen($data);
        $seed = self::u64xor($seed, self::u64shl(self::swap32($seed & 0xFFFFFFFF), 32));
        $i1 = self::readLe32($data, 0);
        $i2 = self::readLe32($data, $ln - 4);
        $bitflip = self::u64sub(self::u64xor(self::readLe64($secret, 8), self::readLe64($secret, 16)), $seed);
        $inp = self::u64add($i2, self::u64shl($i1, 32));

        return self::xxh3Rrmxmx(self::u64xor($inp, $bitflip), $ln);
    }

    private static function len9to16_64(string $data, string $secret, int $seed = 0): int
    {
        $ln = \strlen($data);
        $b1 = self::u64add(self::u64xor(self::readLe64($secret, 24), self::readLe64($secret, 32)), $seed);
        $b2 = self::u64sub(self::u64xor(self::readLe64($secret, 40), self::readLe64($secret, 48)), $seed);
        $lo = self::u64xor(self::readLe64($data, 0), $b1);
        $hi = self::u64xor(self::readLe64($data, $ln - 8), $b2);
        $acc = self::u64add($ln, self::swap64($lo), $hi, self::mul128Fold64($lo, $hi));

        return self::xxh3Avalanche($acc);
    }

    private static function len0to16_64(string $data, string $secret, int $seed = 0): int
    {
        $ln = \strlen($data);
        if ($ln > 8) {
            return self::len9to16_64($data, $secret, $seed);
        }
        if ($ln >= 4) {
            return self::len4to8_64($data, $secret, $seed);
        }
        if ($ln > 0) {
            return self::len1to3_64($data, $secret, $seed);
        }

        return self::xxh64Avalanche(self::u64xor($seed, self::u64xor(self::readLe64($secret, 56), self::readLe64($secret, 64))));
    }

    private static function len17to128_64(string $data, string $secret, int $seed = 0): int
    {
        $ln = \strlen($data);
        $acc = self::u64mul($ln, self::prime64_1());
        if ($ln > 32) {
            if ($ln > 64) {
                if ($ln > 96) {
                    $acc = self::u64add($acc, self::mix16b($data, $secret, 48, 96, $seed), self::mix16b($data, $secret, $ln - 64, 112, $seed));
                }
                $acc = self::u64add($acc, self::mix16b($data, $secret, 32, 64, $seed), self::mix16b($data, $secret, $ln - 48, 80, $seed));
            }
            $acc = self::u64add($acc, self::mix16b($data, $secret, 16, 32, $seed), self::mix16b($data, $secret, $ln - 32, 48, $seed));
        }
        $acc = self::u64add($acc, self::mix16b($data, $secret, 0, 0, $seed), self::mix16b($data, $secret, $ln - 16, 16, $seed));

        return self::xxh3Avalanche($acc);
    }

    private static function len129to240_64(string $data, string $secret, int $seed = 0): int
    {
        $ln = \strlen($data);
        $acc = self::u64mul($ln, self::prime64_1());
        $nb = intdiv($ln, 16);
        for ($i = 0; $i < 8; ++$i) {
            $acc = self::u64add($acc, self::mix16b($data, $secret, 16 * $i, 16 * $i, $seed));
        }
        $accEnd = self::mix16b($data, $secret, $ln - 16, self::SECRET_SIZE_MIN - self::MIDSIZE_LASTOFFSET, $seed);
        $acc = self::xxh3Avalanche($acc);
        for ($i = 8; $i < $nb; ++$i) {
            $accEnd = self::u64add($accEnd, self::mix16b($data, $secret, 16 * $i, 16 * ($i - 8) + self::MIDSIZE_STARTOFFSET, $seed));
        }

        return self::xxh3Avalanche(self::u64add($acc, $accEnd));
    }

    /** @param list<int> $acc */
    private static function scalarRound(array &$acc, string $data, string $secret, int $offInp, int $offSec, int $lane): void
    {
        $dv = self::readLe64($data, $offInp + $lane * 8);
        $dk = self::u64xor($dv, self::readLe64($secret, $offSec + $lane * 8));
        $acc[$lane ^ 1] = self::u64add($acc[$lane ^ 1], $dv);
        $acc[$lane] = self::mult32to64Add64($dk, $dk >> 32, $acc[$lane]);
    }

    /** @param list<int> $acc */
    private static function accumulate512(array &$acc, string $data, string $secret, int $offInp, int $offSec): void
    {
        for ($i = 0; $i < self::ACC_NB; ++$i) {
            self::scalarRound($acc, $data, $secret, $offInp, $offSec, $i);
        }
    }

    /** @param list<int> $acc */
    private static function scrambleAcc(array &$acc, string $secret, int $offSec): void
    {
        for ($lane = 0; $lane < self::ACC_NB; ++$lane) {
            $key = self::readLe64($secret, $offSec + $lane * 8);
            $a = $acc[$lane];
            $a = self::xorshift64($a, 47);
            $a = self::u64xor($a, $key);
            $a = self::u64mul($a, self::PRIME32_1);
            $acc[$lane] = $a;
        }
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    private static function stripesWithTail(string $block): array
    {
        $nFull = intdiv(\strlen($block), self::STRIPE_LEN);
        $stripes = [];
        for ($i = 0; $i < $nFull; ++$i) {
            $stripes[] = substr($block, $i * self::STRIPE_LEN, self::STRIPE_LEN);
        }
        $tail = substr($block, $nFull * self::STRIPE_LEN);

        return [$stripes, $tail];
    }

    /** @param list<int> $acc @param list<string> $stripes */
    private static function roundAccumulate(array &$acc, array $stripes, string $secret, int $secretStripeStart): void
    {
        foreach ($stripes as $i => $stripe) {
            self::accumulate512($acc, $stripe, $secret, 0, ($secretStripeStart + $i) * self::SECRET_CONSUME_RATE);
        }
    }

    /** @param list<int> $acc */
    private static function roundBlock(array &$acc, string $block, string $secret): void
    {
        [$stripes, ] = self::stripesWithTail($block);
        self::roundAccumulate($acc, $stripes, $secret, 0);
        self::scrambleAcc($acc, $secret, \strlen($secret) - self::STRIPE_LEN);
    }

    /** @param list<int> $acc */
    private static function lastRound(array &$acc, string $block, string $lastStripe, string $secret): void
    {
        [$stripes, ] = self::stripesWithTail($block);
        self::roundAccumulate($acc, $stripes, $secret, 0);
        self::accumulate512($acc, $lastStripe, $secret, 0, \strlen($secret) - 71);
    }

    /** @param list<int> $acc */
    private static function finalMerge(array $acc, int $start, string $secret, int $secretOff): int
    {
        $result = self::u64($start);
        for ($i = 0; $i < 4; ++$i) {
            $sa = self::readLe64($secret, $secretOff + 16 * $i);
            $sb = self::readLe64($secret, $secretOff + 16 * $i + 8);
            $result = self::u64add($result, self::mul128Fold64(self::u64xor($acc[2 * $i], $sa), self::u64xor($acc[2 * $i + 1], $sb)));
        }

        return self::xxh3Avalanche($result);
    }

    private static function hashLong64(string $data, string $secret): int
    {
        $acc = [self::PRIME32_3, self::prime64_1(), self::prime64_2(), self::PRIME64_3, self::prime64_4(), self::PRIME32_2, self::PRIME64_5, self::PRIME32_1];
        $stripesPerBlock = intdiv(\strlen($secret) - self::STRIPE_LEN, self::SECRET_CONSUME_RATE);
        $blockSize = self::STRIPE_LEN * $stripesPerBlock;
        $fullBlockCount = intdiv(\strlen($data), $blockSize);
        $remainderLen = \strlen($data) % $blockSize;
        if (0 === $remainderLen && $fullBlockCount > 0) {
            --$fullBlockCount;
            $remainderLen = $blockSize;
        }
        for ($n = 0; $n < $fullBlockCount; ++$n) {
            $block = substr($data, $n * $blockSize, $blockSize);
            self::roundBlock($acc, $block, $secret);
        }
        $lastBlockOff = $fullBlockCount * $blockSize;
        $lastBlock = substr($data, $lastBlockOff, $remainderLen);
        $lastStripe = substr($data, -self::STRIPE_LEN);
        self::lastRound($acc, $lastBlock, $lastStripe, $secret);

        return self::finalMerge($acc, self::u64mul(\strlen($data), self::prime64_1()), $secret, self::SECRET_MERGEACCS_START);
    }

    private static function len1to3_128(string $data, string $secret, int $seed = 0): array
    {
        $ln = \strlen($data);
        $c1 = \ord($data[0]);
        $c2 = \ord($data[$ln >> 1]);
        $c3 = \ord($data[$ln - 1]);
        $combinedl = ($c1 << 16) | ($c2 << 24) | $c3 | ($ln << 8);
        $combinedh = self::rotl32(self::swap32($combinedl), 13);
        $bl = (self::readLe32($secret, 0) ^ self::readLe32($secret, 4)) + $seed;
        $bh = (self::readLe32($secret, 8) ^ self::readLe32($secret, 12)) - $seed;

        return [self::xxh64Avalanche(self::u64xor($combinedl, $bl)), self::xxh64Avalanche(self::u64xor($combinedh, $bh))];
    }

    private static function len4to8_128(string $data, string $secret, int $seed = 0): array
    {
        $ln = \strlen($data);
        $seed = self::u64xor($seed, self::u64shl(self::swap32($seed & 0xFFFFFFFF), 32));
        $ilo = self::readLe32($data, 0);
        $ihi = self::readLe32($data, $ln - 4);
        $inp = self::u64add($ilo, self::u64shl($ihi, 32));
        $bitflip = self::u64add(self::u64xor(self::readLe64($secret, 16), self::readLe64($secret, 24)), $seed);
        $keyed = self::u64xor($inp, $bitflip);
        [$lo, $hi] = self::mult64to128($keyed, self::u64add(self::prime64_1(), self::u64shl($ln, 2)));
        $hi = self::u64add($hi, self::u64shl($lo, 1));
        $lo = self::u64xor($lo, self::u64shr($hi, 3));
        $lo = self::xorshift64($lo, 35);
        $lo = self::u64mul($lo, self::primeMx2());
        $lo = self::xorshift64($lo, 28);
        $hi = self::xxh3Avalanche($hi);

        return [$lo, $hi];
    }

    private static function len9to16_128(string $data, string $secret, int $seed = 0): array
    {
        $ln = \strlen($data);
        $bl = self::u64sub(self::u64xor(self::readLe64($secret, 32), self::readLe64($secret, 40)), $seed);
        $bh = self::u64add(self::u64xor(self::readLe64($secret, 48), self::readLe64($secret, 56)), $seed);
        $ilo = self::readLe64($data, 0);
        $ihi = self::readLe64($data, $ln - 8);
        [$lo, $hi] = self::mult64to128(self::u64xor(self::u64xor($ilo, $ihi), $bl), self::prime64_1());
        $lo = self::u64add($lo, self::u64shl($ln - 1, 54));
        $ihi = self::u64xor($ihi, $bh);
        $hi = self::u64add($hi, $ihi, self::mult32to64(self::u32($ihi), self::PRIME32_2 - 1));
        $lo = self::u64xor($lo, self::swap64($hi));
        [$hlo, $hhi] = self::mult64to128($lo, self::prime64_2());
        $hhi = self::u64add($hhi, self::u64mul($hi, self::prime64_2()));

        return [self::xxh3Avalanche($hlo), self::xxh3Avalanche($hhi)];
    }

    private static function len0to16_128(string $data, string $secret, int $seed = 0): array
    {
        $ln = \strlen($data);
        if ($ln > 8) {
            return self::len9to16_128($data, $secret, $seed);
        }
        if ($ln >= 4) {
            return self::len4to8_128($data, $secret, $seed);
        }
        if ($ln > 0) {
            return self::len1to3_128($data, $secret, $seed);
        }
        $bl = self::readLe64($secret, 64) ^ self::readLe64($secret, 72);
        $bh = self::readLe64($secret, 80) ^ self::readLe64($secret, 88);

        return [self::xxh64Avalanche(self::u64xor($seed, $bl)), self::xxh64Avalanche(self::u64xor($seed, $bh))];
    }

  /** @return array{0:int,1:int} */
    private static function mix32b(int $accLo, int $accHi, string $data, int $off1, int $off2, string $secret, int $offSec, int $seed): array
    {
        $accLo = self::u64add($accLo, self::mix16b($data, $secret, $off1, $offSec, $seed));
        $accLo = self::u64xor($accLo, self::u64add(self::readLe64($data, $off2), self::readLe64($data, $off2 + 8)));
        $accHi = self::u64add($accHi, self::mix16b($data, $secret, $off2, $offSec + 16, $seed));
        $accHi = self::u64xor($accHi, self::u64add(self::readLe64($data, $off1), self::readLe64($data, $off1 + 8)));

        return [$accLo, $accHi];
    }

    private static function len17to128_128(string $data, string $secret, int $seed = 0): array
    {
        $ln = \strlen($data);
        $lo = self::u64mul($ln, self::prime64_1());
        $hi = 0;
        if ($ln > 32) {
            if ($ln > 64) {
                if ($ln > 96) {
                    [$lo, $hi] = self::mix32b($lo, $hi, $data, 48, $ln - 64, $secret, 96, $seed);
                }
                [$lo, $hi] = self::mix32b($lo, $hi, $data, 32, $ln - 48, $secret, 64, $seed);
            }
            [$lo, $hi] = self::mix32b($lo, $hi, $data, 16, $ln - 32, $secret, 32, $seed);
        }
        [$lo, $hi] = self::mix32b($lo, $hi, $data, 0, $ln - 16, $secret, 0, $seed);
        $hlo = self::u64add($lo, $hi);
        $hhi = self::u64add(self::u64mul($lo, self::prime64_1()), self::u64mul($hi, self::prime64_4()), self::u64mul($ln - $seed, self::prime64_2()));

        return [self::xxh3Avalanche($hlo), self::u64sub(0, self::xxh3Avalanche($hhi))];
    }

    private static function len129to240_128(string $data, string $secret, int $seed = 0): array
    {
        $ln = \strlen($data);
        $lo = self::u64mul($ln, self::prime64_1());
        $hi = 0;
        for ($i = 32; $i < 160; $i += 32) {
            [$lo, $hi] = self::mix32b($lo, $hi, $data, $i - 32, $i - 16, $secret, $i - 32, $seed);
        }
        $lo = self::xxh3Avalanche($lo);
        $hi = self::xxh3Avalanche($hi);
        $i = 160;
        while ($i <= $ln) {
            [$lo, $hi] = self::mix32b($lo, $hi, $data, $i - 32, $i - 16, $secret, self::MIDSIZE_STARTOFFSET + $i - 160, $seed);
            $i += 32;
        }
        [$lo, $hi] = self::mix32b($lo, $hi, $data, $ln - 16, $ln - 32, $secret, self::SECRET_SIZE_MIN - self::MIDSIZE_LASTOFFSET - 16, self::u64sub(0, $seed));
        $hlo = self::u64add($lo, $hi);
        $hhi = self::u64add(self::u64mul($lo, self::prime64_1()), self::u64mul($hi, self::prime64_4()), self::u64mul($ln - $seed, self::prime64_2()));

        return [self::xxh3Avalanche($hlo), self::u64sub(0, self::xxh3Avalanche($hhi))];
    }

    /** @return array{0:int,1:int} */
    private static function hashLong128(string $data, string $secret): array
    {
        $acc = [self::PRIME32_3, self::prime64_1(), self::prime64_2(), self::PRIME64_3, self::prime64_4(), self::PRIME32_2, self::PRIME64_5, self::PRIME32_1];
        $stripesPerBlock = intdiv(\strlen($secret) - self::STRIPE_LEN, self::SECRET_CONSUME_RATE);
        $blockSize = self::STRIPE_LEN * $stripesPerBlock;
        $fullBlockCount = intdiv(\strlen($data), $blockSize);
        $remainderLen = \strlen($data) % $blockSize;
        if (0 === $remainderLen && $fullBlockCount > 0) {
            --$fullBlockCount;
            $remainderLen = $blockSize;
        }
        for ($n = 0; $n < $fullBlockCount; ++$n) {
            $block = substr($data, $n * $blockSize, $blockSize);
            self::roundBlock($acc, $block, $secret);
        }
        $lastBlockOff = $fullBlockCount * $blockSize;
        $lastBlock = substr($data, $lastBlockOff, $remainderLen);
        $lastStripe = substr($data, -self::STRIPE_LEN);
        self::lastRound($acc, $lastBlock, $lastStripe, $secret);
        $lo = self::finalMerge($acc, self::u64mul(\strlen($data), self::prime64_1()), $secret, self::SECRET_MERGEACCS_START);
        $hi = self::finalMerge(
            $acc,
            self::u64xor(self::u64mul(\strlen($data), self::prime64_2()), 0xFFFFFFFFFFFFFFFF),
            $secret,
            \strlen($secret) - 64 - self::SECRET_MERGEACCS_START,
        );

        return [$lo, $hi];
    }

    /** @return list<int> */
    private static function u64DigestBytes(int $value): array
    {
        /** @var list<int> $bytes */
        $bytes = array_values(unpack('C8', pack('J', $value)));

        return $bytes;
    }
}
