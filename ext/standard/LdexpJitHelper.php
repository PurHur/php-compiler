<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ldexp() for compiled JIT/AOT modules (#15073, #29578, php-in-PHP).
 *
 * NestedJIT-safe: scale by bounded ×2/÷2 (#29578 / peer MathFrexp #29156).
 * Do not call the shared VmMath ldexp helper — `\is_nan` / `\is_infinite` /
 * pow-of-two re-enter math bridges under thin AOT (#27496 class). Avoid
 * compound `&&` / `||` conditions — NestedJIT assignOperand bool→double
 * (#28716). Avoid unbounded while-loops (#27838).
 * php-src: ext/standard/math.c — PHP_FUNCTION(ldexp)
 * Userland ldexp() is a php-src phantom and was unregistered (#24607).
 */
final class LdexpJitHelper
{
    public static function ldexpArgv(float $num, int $exp): float
    {
        if (0.0 === $num) {
            return $num;
        }
        if (0 === $exp) {
            return $num;
        }

        if ($num !== $num) {
            return $num;
        }

        $inf = 1.0e+308;
        $inf = $inf * $inf;
        if ($num === $inf) {
            return $num;
        }
        if ($num === -$inf) {
            return $num;
        }

        $m = $num;
        if ($exp > 0) {
            $n = $exp;
            if ($n > 2048) {
                $n = 2048;
            }
            for ($i = 0; $i < $n; ++$i) {
                $m *= 2.0;
            }

            return $m;
        }

        $n = -$exp;
        if ($n > 2048) {
            $n = 2048;
        }
        for ($i = 0; $i < $n; ++$i) {
            $m *= 0.5;
        }

        return $m;
    }
}
