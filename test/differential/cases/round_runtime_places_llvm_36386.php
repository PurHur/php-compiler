<?php

// round() runtime $places + known mode via llvm.pow.f64 scale (#36386)
// php-src: ext/standard/math.c _php_math_round / pow(10.0, (double) places)
declare(strict_types=1);

function work(float $x, int $p): void
{
    echo round($x, $p), '|';
    echo round($x, $p, PHP_ROUND_HALF_UP), '|';
    echo round($x, $p, PHP_ROUND_HALF_DOWN), '|';
    echo round($x, $p, PHP_ROUND_HALF_EVEN), "\n";
}

work(2.675, 2);
work(1.25, 1);
work(1.5, 0);
work(-1.55, 1);
work(0.005, 2);
work(123.456, -1);
