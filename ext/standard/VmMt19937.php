<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Mersenne Twister MT19937 — php-src ext/random/engine_mt19937.c (#11908, #3295).
 *
 * Shared by rand() / mt_rand() on the Zend 8.2 reference profile.
 */
final class VmMt19937
{
    public const MT_N = 624;

    public const MT_M = 397;

    public const PHP_MT_RAND_MAX = 0x7FFFFFFF;

    public const MT_RAND_MT19937 = 0;

    public const MT_RAND_PHP = 1;

    /** @var list<int> */
    private static array $state = [];

    private static int $count = self::MT_N;

    private static int $mode = self::MT_RAND_MT19937;

    private static bool $seeded = false;

    public static function resetForTests(): void
    {
        self::$state = [];
        self::$count = self::MT_N;
        self::$mode = self::MT_RAND_MT19937;
        self::$seeded = false;
    }

    public static function seed(int $seed, int $mode = self::MT_RAND_MT19937): void
    {
        self::$mode = $mode;
        self::$state = [$seed & 0xFFFFFFFF];
        for ($i = 1; $i < self::MT_N; ++$i) {
            $prev = self::$state[$i - 1];
            self::$state[$i] = (1812433253 * (($prev ^ ($prev >> 30)) & 0xFFFFFFFF) + $i) & 0xFFFFFFFF;
        }
        self::$count = $i;
        self::reload();
        self::$seeded = true;
    }

    public static function ensureSeeded(): void
    {
        if (!self::$seeded) {
            self::seedDefault();
        }
    }

    private static function seedDefault(): void
    {
        try {
            $bytes = VmString::randomBytes(8);
            $seed = \unpack('P', $bytes)[1];
            if (!\is_int($seed)) {
                $seed = \time();
            }
        } catch (\Throwable) {
            $seed = \time();
        }
        self::seed($seed & 0xFFFFFFFF);
    }

    public static function generate(): int
    {
        self::ensureSeeded();
        if (self::$count >= self::MT_N) {
            self::reload();
        }
        $s1 = self::$state[self::$count++];
        $s1 ^= ($s1 >> 11) & 0xFFFFFFFF;
        $s1 ^= ($s1 << 7) & 0x9D2C5680;
        $s1 &= 0xFFFFFFFF;
        $s1 ^= ($s1 << 15) & 0xEFC60000;
        $s1 &= 0xFFFFFFFF;

        return ($s1 ^ ($s1 >> 18)) & 0xFFFFFFFF;
    }

    /** php_mt_rand() — full 32-bit draw. */
    public static function mtRand(): int
    {
        return self::generate();
    }

    /** genrand_int31 — upper 31 bits (php_mt_rand() >> 1 for rand()/mt_rand() no-arg). */
    public static function mtRand31(): int
    {
        return self::generate() >> 1;
    }

    /**
     * Unbiased int in [min, max] — MT_RAND_MT19937 mode (php_random_range / rand_range32).
     */
    public static function range(int $min, int $max): int
    {
        if ($max < $min) {
            throw new \ValueError(
                \sprintf(
                    'mt_rand(): Argument #2 ($max) must be greater than or equal to argument #1 ($min)'
                )
            );
        }
        if ($min === $max) {
            return $min;
        }

        $umax = $max - $min;
        if ($umax > 0xFFFFFFFF) {
            return $min + self::range64($umax);
        }

        return $min + self::range32($umax);
    }

    /**
     * rand() range — swaps when max < min (php_mt_rand_common with MT_RAND_MT19937).
     */
    public static function randRange(int $min, int $max): int
    {
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }

        return self::range($min, $max);
    }

    private static function range32(int $umax): int
    {
        if (0xFFFFFFFF === $umax) {
            return self::generate();
        }

        ++$umax;
        if (($umax & ($umax - 1)) === 0) {
            return self::generate() & ($umax - 1);
        }

        $limit = 0xFFFFFFFF - (int) (0xFFFFFFFF % $umax) - 1;
        $result = self::generate();
        while ($result > $limit) {
            $result = self::generate();
        }

        return $result % $umax;
    }

    private static function range64(int $umax): int
    {
        ++$umax;
        $limit = \PHP_INT_MAX - (int) (\PHP_INT_MAX % $umax) - 1;
        $result = self::generate();
        while ($result > $limit) {
            $result = self::generate();
        }

        return $result % $umax;
    }

    private static function reload(): void
    {
        if (self::MT_RAND_PHP === self::$mode) {
            self::reloadPhp();
        } else {
            self::reloadMt19937();
        }
        self::$count = 0;
    }

    private static function reloadMt19937(): void
    {
        $n = self::MT_N;
        $m = self::MT_M;
        for ($i = 0; $i < $n - $m; ++$i) {
            self::$state[$i] = self::twist(self::$state[$i + $m], self::$state[$i], self::$state[$i + 1], false);
        }
        for ($i = $n - $m; $i < $n - 1; ++$i) {
            self::$state[$i] = self::twist(self::$state[$i + $m - $n], self::$state[$i], self::$state[$i + 1], false);
        }
        self::$state[$n - 1] = self::twist(self::$state[$m - 1], self::$state[$n - 1], self::$state[0], false);
    }

    private static function reloadPhp(): void
    {
        $n = self::MT_N;
        $m = self::MT_M;
        for ($i = 0; $i < $n - $m; ++$i) {
            self::$state[$i] = self::twist(self::$state[$i + $m], self::$state[$i], self::$state[$i + 1], true);
        }
        for ($i = $n - $m; $i < $n - 1; ++$i) {
            self::$state[$i] = self::twist(self::$state[$i + $m - $n], self::$state[$i], self::$state[$i + 1], true);
        }
        self::$state[$n - 1] = self::twist(self::$state[$m - 1], self::$state[$n - 1], self::$state[0], true);
    }

    private static function twist(int $m, int $u, int $v, bool $phpMode): int
    {
        $mix = (self::hiBit($u) | self::loBits($v)) & 0xFFFFFFFF;
        $lo = $phpMode ? self::loBit($u) : self::loBit($v);
        $mask = (-$lo) & 0x9908B0DF;

        return ($m ^ (($mix >> 1) & 0x7FFFFFFF) ^ $mask) & 0xFFFFFFFF;
    }

    private static function hiBit(int $u): int
    {
        return $u & 0x80000000;
    }

    private static function loBit(int $u): int
    {
        return $u & 1;
    }

    private static function loBits(int $u): int
    {
        return $u & 0x7FFFFFFF;
    }
}
