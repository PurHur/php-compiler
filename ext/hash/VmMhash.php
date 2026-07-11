<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmHash;
use PHPCompiler\ext\standard\VmMath;

/**
 * mhash compatibility helpers (php-src ext/hash/hash.c; #14975).
 */
final class VmMhash
{
    private const SALT_SIZE = 8;

    public static function mhash(int $algorithm, string $data): string|false
    {
        $meta = MhashRegistry::lookup($algorithm);
        if (null === $meta) {
            return false;
        }

        try {
            return VmHash::hash($meta['hash_algo'], $data, true);
        } catch (\ValueError) {
            return false;
        }
    }

    public static function getHashName(int $algorithm): string|false
    {
        $meta = MhashRegistry::lookup($algorithm);

        return null === $meta ? false : $meta['name'];
    }

    public static function getBlockSize(int $algorithm): int|false
    {
        $meta = MhashRegistry::lookup($algorithm);

        return null === $meta ? false : $meta['block_size'];
    }

    public static function keygenS2k(int $algorithm, string $password, string $salt, int $bytes): string|false
    {
        if ($bytes <= 0) {
            throw new \ValueError('mhash_keygen_s2k(): Argument #4 ($bytes) must be greater than 0');
        }

        $meta = MhashRegistry::lookup($algorithm);
        if (null === $meta) {
            return false;
        }

        $paddedSalt = \str_pad(\substr($salt, 0, self::SALT_SIZE), self::SALT_SIZE, "\0", STR_PAD_RIGHT);
        $blockSize = $meta['digest_size'];
        $times = intdiv($bytes, $blockSize);
        if (0 !== $bytes % $blockSize) {
            ++$times;
        }

        $key = '';
        for ($i = 0; $i < $times; ++$i) {
            $prefix = \str_repeat("\0", $i);
            try {
                $digest = VmHash::hash($meta['hash_algo'], $prefix.$paddedSalt.$password, true);
            } catch (\ValueError) {
                return false;
            }
            $key .= \substr($digest, 0, $blockSize);
        }

        return \substr($key, 0, $bytes);
    }

    public static function coerceAlgorithmArg(
        \PHPCompiler\VM\Variable $var,
        string $function,
        int $argIndex,
        string $param
    ): int {
        return VmMath::parseIntBuiltinArg($var, $function, $argIndex, $param);
    }

    public static function coerceByteLengthArg(
        \PHPCompiler\VM\Variable $var,
        string $function,
        int $argIndex,
        string $param
    ): int {
        return VmMath::parseIntBuiltinArg($var, $function, $argIndex, $param);
    }
}
