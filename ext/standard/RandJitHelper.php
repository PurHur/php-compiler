<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for rand()/mt_rand() (#11908).
 *
 * php-src: ext/random/engine_mt19937.c
 */
final class RandJitHelper
{
    public static function mtRand31(): int
    {
        return VmMt19937::mtRand31();
    }

    public static function randRange(int $min, int $max): int
    {
        return VmMt19937::randRange($min, $max);
    }

    public static function mtRandRange(int $min, int $max): int
    {
        return VmMt19937::range($min, $max);
    }

    public static function seed(int $seed): void
    {
        VmMt19937::seed($seed);
    }
}
