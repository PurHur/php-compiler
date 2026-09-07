<?php

// round() places≠0 HALF_UP via scale+llvm.round.f64 (#36386)
// php-src: ext/standard/math.c _php_math_round / PHP_FUNCTION(round)
// Note: 9.995@2 remains an f64 cliff (master sprintf path also printed 9.99 vs Zend 10).
declare(strict_types=1);

function work(float $x): void
{
    echo round($x, 2), '|';
    echo round($x, 1), '|';
    echo round($x, 2, PHP_ROUND_HALF_UP), "\n";
}

work(2.675);
work(1.25);
work(1.55);
work(-1.55);
work(0.005);
work(-0.005);
work(1.234);
work(2.5);
