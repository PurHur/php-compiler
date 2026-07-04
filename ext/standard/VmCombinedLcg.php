<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Combined linear congruential generator — php-src ext/random/random.c php_combined_lcg() (#3295).
 */
final class VmCombinedLcg
{
    private const SCALE = 4.656613e-10;

    /** @var array{0: int, 1: int} */
    private static array $state = [0, 0];

    private static bool $seeded = false;

    public static function resetForTests(): void
    {
        self::$state = [0, 0];
        self::$seeded = false;
    }

    /** Test hook — seed both LCG streams (php_combined_lcg state[0]/state[1]). */
    public static function seed(int $s0, int $s1): void
    {
        self::$state = [self::toInt32($s0), self::toInt32($s1)];
        self::$seeded = true;
    }

    public static function value(): float
    {
        if (!self::$seeded) {
            self::seedDefault();
        }

        self::modmult(53668, 40014, 12211, 2147483563, self::$state[0]);
        self::modmult(52774, 40692, 3791, 2147483399, self::$state[1]);
        $z = self::$state[0] - self::$state[1];
        if ($z < 1) {
            $z += 2147483562;
        }

        return $z * self::SCALE;
    }

    private static function seedDefault(): void
    {
        try {
            $bytes = VmString::randomBytes(8);
            $packed = \unpack('P', $bytes);
            $seed = $packed[1] ?? 0;
            if (!\is_int($seed)) {
                $seed = \time();
            }
        } catch (\Throwable) {
            $seed = \time();
        }
        self::seed($seed & 0xFFFFFFFF, ($seed >> 32) & 0xFFFFFFFF);
    }

    private static function modmult(int $a, int $b, int $c, int $m, int &$s): void
    {
        $q = intdiv($s, $a);
        $s = $b * ($s - $a * $q) - $c * $q;
        if ($s < 0) {
            $s += $m;
        }
        $s = self::toInt32($s);
    }

    private static function toInt32(int $value): int
    {
        $value &= 0xFFFFFFFF;
        if ($value > 0x7FFFFFFF) {
            $value -= 0x100000000;
        }

        return $value;
    }
}
