<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure PHP SHA-3 (FIPS 202 Keccak) digests — no host hash() (issue #12903).
 *
 * php-src: ext/hash/hash_sha.c — PHP_SHA3_* family
 */
final class VmHashSha3Pure
{
    /** @var list<int> */
    private const ROTC = [
        1, 3, 6, 10, 15, 21, 28, 36, 45, 55, 2, 14, 27, 41, 56, 8, 25, 43, 62, 18, 39, 61, 20, 44,
    ];

    /** @var list<int> */
    private const PILN = [
        10, 7, 11, 17, 18, 3, 5, 16, 8, 21, 24, 4, 15, 23, 19, 13, 12, 2, 20, 14, 22, 9, 6, 1,
    ];

    /** SHA-3 domain suffix (NIST); Keccak uses 0x01. */
    private const SHA3_SUFFIX = 0x06;

    /** @return list<int> */
    public static function sha3_224(string $data): array
    {
        return self::digestBytes(self::keccak($data, 224, 224, self::SHA3_SUFFIX));
    }

    /** @return list<int> */
    public static function sha3_256(string $data): array
    {
        return self::digestBytes(self::keccak($data, 256, 256, self::SHA3_SUFFIX));
    }

    /** @return list<int> */
    public static function sha3_384(string $data): array
    {
        return self::digestBytes(self::keccak($data, 384, 384, self::SHA3_SUFFIX));
    }

    /** @return list<int> */
    public static function sha3_512(string $data): array
    {
        return self::digestBytes(self::keccak($data, 512, 512, self::SHA3_SUFFIX));
    }

    private static function keccak(string $data, int $capacityBits, int $outputBits, int $suffix): string
    {
        $capacity = $capacityBits >> 3;
        $rate = 200 - 2 * $capacity;
        $rateWords = $rate >> 3;
        $inLen = \strlen($data);
        $offset = 0;

        /** @var list<array{0:int,1:int}> */
        $state = [];
        for ($i = 0; $i < 25; $i++) {
            $state[$i] = [0, 0];
        }

        while ($inLen >= $rate) {
            self::xorBlock($state, \substr($data, $offset, $rate), $rateWords);
            self::keccakF($state);
            $offset += $rate;
            $inLen -= $rate;
        }

        $tail = \substr($data, $offset, $inLen);
        $block = \str_pad($tail, $rate, "\0", STR_PAD_RIGHT);
        $block[$inLen] = \chr($suffix & 0xFF);
        $block[$rate - 1] = \chr((\ord($block[$rate - 1]) | 0x80) & 0xFF);
        self::xorBlock($state, $block, $rateWords);
        self::keccakF($state);

        $out = '';
        for ($i = 0; $i < 25; $i++) {
            $out .= \pack('V*', $state[$i][1], $state[$i][0]);
        }

        return \substr($out, 0, $outputBits >> 3);
    }

    /**
     * @param list<array{0:int,1:int}> $state
     */
    private static function xorBlock(array &$state, string $block, int $words): void
    {
        for ($i = 0; $i < $words; $i++) {
            $chunk = \substr($block, $i << 3, 8);
            if (\strlen($chunk) < 8) {
                $chunk = \str_pad($chunk, 8, "\0");
            }
            $t = \unpack('V*', $chunk);
            $state[$i] = [
                self::u32($state[$i][0] ^ $t[2]),
                self::u32($state[$i][1] ^ $t[1]),
            ];
        }
    }

    /**
     * @param list<array{0:int,1:int}> $st
     */
    private static function keccakF(array &$st): void
    {
        $rc = [
            [0x00000000, 0x00000001], [0x00000000, 0x00008082], [0x80000000, 0x0000808a], [0x80000000, 0x80008000],
            [0x00000000, 0x0000808b], [0x00000000, 0x80000001], [0x80000000, 0x80008081], [0x80000000, 0x00008009],
            [0x00000000, 0x0000008a], [0x00000000, 0x00000088], [0x00000000, 0x80008009], [0x00000000, 0x8000000a],
            [0x00000000, 0x8000808b], [0x80000000, 0x0000008b], [0x80000000, 0x00008089], [0x80000000, 0x00008003],
            [0x80000000, 0x00008002], [0x80000000, 0x00000080], [0x00000000, 0x0000800a], [0x80000000, 0x8000000a],
            [0x80000000, 0x80008081], [0x80000000, 0x00008080], [0x00000000, 0x80000001], [0x80000000, 0x80008008],
        ];

        $bc = [];
        for ($round = 0; $round < 24; $round++) {
            for ($i = 0; $i < 5; $i++) {
                $bc[$i] = [
                    $st[$i][0] ^ $st[$i + 5][0] ^ $st[$i + 10][0] ^ $st[$i + 15][0] ^ $st[$i + 20][0],
                    $st[$i][1] ^ $st[$i + 5][1] ^ $st[$i + 10][1] ^ $st[$i + 15][1] ^ $st[$i + 20][1],
                ];
            }

            for ($i = 0; $i < 5; $i++) {
                $t = [
                    self::u32($bc[($i + 4) % 5][0] ^ (($bc[($i + 1) % 5][0] << 1) | ($bc[($i + 1) % 5][1] >> 31))),
                    self::u32($bc[($i + 4) % 5][1] ^ (($bc[($i + 1) % 5][1] << 1) | ($bc[($i + 1) % 5][0] >> 31))),
                ];
                for ($j = 0; $j < 25; $j += 5) {
                    $st[$j + $i] = [
                        self::u32($st[$j + $i][0] ^ $t[0]),
                        self::u32($st[$j + $i][1] ^ $t[1]),
                    ];
                }
            }

            $t = $st[1];
            for ($i = 0; $i < 24; $i++) {
                $j = self::PILN[$i];
                $bc[0] = $st[$j];
                $n = self::ROTC[$i];
                $hi = $t[0];
                $lo = $t[1];
                if ($n >= 32) {
                    $n -= 32;
                    $hi = $t[1];
                    $lo = $t[0];
                }
                $st[$j] = [
                    self::u32((($hi << $n) | ($lo >> (32 - $n)))),
                    self::u32((($lo << $n) | ($hi >> (32 - $n)))),
                ];
                $t = $bc[0];
            }

            for ($j = 0; $j < 25; $j += 5) {
                for ($i = 0; $i < 5; $i++) {
                    $bc[$i] = $st[$j + $i];
                }
                for ($i = 0; $i < 5; $i++) {
                    $st[$j + $i] = [
                        self::u32($st[$j + $i][0] ^ (~$bc[($i + 1) % 5][0] & $bc[($i + 2) % 5][0])),
                        self::u32($st[$j + $i][1] ^ (~$bc[($i + 1) % 5][1] & $bc[($i + 2) % 5][1])),
                    ];
                }
            }

            $st[0] = [
                self::u32($st[0][0] ^ $rc[$round][0]),
                self::u32($st[0][1] ^ $rc[$round][1]),
            ];
        }
    }

    /** @return list<int> */
    private static function digestBytes(string $raw): array
    {
        return \array_values(\unpack('C*', $raw));
    }

    private static function u32(int $x): int
    {
        return $x & 0xFFFFFFFF;
    }
}
