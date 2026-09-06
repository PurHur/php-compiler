<?php

// round() places=0 HALF_DOWN/EVEN/ODD via LLVM trunc+select (#36386)
// php-src: ext/standard/math.c _php_math_round / php_math_round_mode.h
declare(strict_types=1);

function work(float $x): void
{
    echo round($x, 0, PHP_ROUND_HALF_DOWN), '|';
    echo round($x, 0, PHP_ROUND_HALF_EVEN), '|';
    echo round($x, 0, PHP_ROUND_HALF_ODD), "\n";
}

work(1.5);
work(2.5);
work(-1.5);
work(0.5);
work(-0.5);
work(1.1);
work(-1.1);
work(3.5);
