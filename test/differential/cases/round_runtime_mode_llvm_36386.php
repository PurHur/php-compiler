<?php

// round() runtime $mode via icmp+select among places=0 LLVM paths (#36386)
// php-src: ext/standard/math.c _php_math_round / php_math_round_mode.h
// Modes 5–8 (CEILING/FLOOR/…) are PHP 8.4-only; pinned Zend 8.2 treats them as
// HALF_UP — differential covers HALF_* only (see NativeRoundLlvmAotTest for 5–8).
declare(strict_types=1);

function work(float $x, int $mode): void
{
    echo round($x, 0, $mode), '|';
}

function workPlaces(float $x, int $p, int $mode): void
{
    echo round($x, $p, $mode), '|';
}

work(1.5, PHP_ROUND_HALF_UP);
work(1.5, PHP_ROUND_HALF_DOWN);
work(1.5, PHP_ROUND_HALF_EVEN);
work(1.5, PHP_ROUND_HALF_ODD);
work(2.5, PHP_ROUND_HALF_UP);
work(2.5, PHP_ROUND_HALF_EVEN);
work(-1.5, PHP_ROUND_HALF_DOWN);
work(-1.5, PHP_ROUND_HALF_ODD);
echo "\n";
workPlaces(2.675, 2, PHP_ROUND_HALF_UP);
workPlaces(2.675, 2, PHP_ROUND_HALF_DOWN);
workPlaces(1.25, 1, PHP_ROUND_HALF_EVEN);
workPlaces(1.5, 0, PHP_ROUND_HALF_DOWN);
workPlaces(-1.55, 1, PHP_ROUND_HALF_UP);
echo "\n";
