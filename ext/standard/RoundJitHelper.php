<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * round() for compiled JIT/AOT modules (#15211, php-in-PHP).
 *
 * SSOT: {@see VmRound::mathRound()}
 * php-src: ext/standard/math.c — _php_math_round
 */
final class RoundJitHelper
{
    public static function roundArgv(float $num, int $places, int $mode): float
    {
        return VmRound::mathRound($num, $places, $mode);
    }
}
