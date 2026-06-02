<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * CSPRNG helpers for stdlib (issue #2330).
 */
final class VmRandom
{
    private const UINT32_MAX = 0xFFFFFFFF;

    /**
     * random_int() — unbiased integer in [min, max] via rejection sampling.
     *
     * @throws \ValueError when min > max
     * @throws \LogicException when span exceeds signed int64 range in this build
     */
    public static function randomInt(int $min, int $max): int
    {
        if ($min > $max) {
            throw new \ValueError(
                'random_int(): Argument #1 ($min) must be less than or equal to argument #2 ($max)'
            );
        }
        if ($min === $max) {
            return $min;
        }

        $range = $max - $min;
        if ($range < 0) {
            throw new \LogicException(
                'random_int() span wider than PHP_INT_MAX in this compiler build; narrow min/max'
            );
        }

        $ceiling = $range + 1;
        if ($ceiling <= self::UINT32_MAX) {
            $limit = (int) ((self::UINT32_MAX / $ceiling) * $ceiling + $ceiling - 1);
            $byteLen = 4;
        } else {
            $limit = (int) ((\PHP_INT_MAX / $ceiling) * $ceiling + $ceiling - 1);
            $byteLen = 8;
        }

        for ($attempt = 0; $attempt < 256; ++$attempt) {
            $bytes = VmString::randomBytes($byteLen);
            $val = 4 === $byteLen
                ? \unpack('V', $bytes)[1]
                : self::bytesToUInt64($bytes);
            if ($val <= $limit) {
                return $min + (int) ($val % $ceiling);
            }
        }

        throw new \Exception('Could not gather sufficient random data');
    }

    private static function bytesToUInt64(string $bytes): int
    {
        $parts = \unpack('C*', $bytes);
        $v = 0;
        for ($i = 1; $i <= 8; ++$i) {
            $v = ($v << 8) | $parts[$i];
        }

        return $v;
    }
}
