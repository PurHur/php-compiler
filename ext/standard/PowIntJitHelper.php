<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * int**int fast path for compiled JIT/AOT modules (#9515, php-in-PHP).
 *
 * VM SSOT: {@see VmMath::powInt}
 * php-src: Zend/zend_operators.c — pow_function integer fast path
 */
final class PowIntJitHelper
{
    private static int $lastInt = 0;

    private static float $lastFloat = 0.0;

    /** @return int 0=int result, 1=float result (LLVM i32 ABI) */
    public static function compute(int $base, int $exp): int
    {
        $result = VmMath::powInt($base, $exp);
        if (\is_int($result)) {
            self::$lastInt = $result;

            return 0;
        }
        self::$lastFloat = $result;

        return 1;
    }

    public static function resultInt(): int
    {
        return self::$lastInt;
    }

    public static function resultFloat(): float
    {
        return self::$lastFloat;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$lastInt = 0;
        self::$lastFloat = 0.0;
    }
}
