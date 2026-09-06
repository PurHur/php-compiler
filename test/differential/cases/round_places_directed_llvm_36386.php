<?php

// round() places≠0 directed modes via scale+LLVM (#36386)
// php-src: ext/standard/math.c _php_math_round / php_math_round_mode.h
declare(strict_types=1);

function work(float $x): void
{
    echo round($x, 2, PHP_ROUND_HALF_DOWN), '|';
    echo round($x, 2, PHP_ROUND_HALF_EVEN), '|';
    echo round($x, 2, PHP_ROUND_HALF_ODD), "\n";
}

work(2.675);
work(1.234);
work(1.236);
work(-2.675);
work(1.251);
work(0.005);
work(-0.005);
