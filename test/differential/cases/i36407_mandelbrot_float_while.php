<?php
// #36407: pins mandelbrot-style float while-loop (Julia iteration) — wave-1 timeout was
// allocator thrash in delref/dtor path; fixed by reverting broken HashTable/Object dtor CFG (#36331).

function julia_iters(float $rec, float $imc): int
{
    $re = $rec;
    $im = $imc;
    $color = 1000;
    $re2 = $re * $re;
    $im2 = $im * $im;
    while ((($re2 + $im2) < 1000000) && $color > 0) {
        $im = $re * $im * 2 + $imc;
        $re = $re2 - $im2 + $rec;
        $re2 = $re * $re;
        $im2 = $im * $im;
        $color = $color - 1;
    }

    return $color;
}

echo julia_iters(-0.45, 0.0), "\n";
echo julia_iters(0.1, 0.12), "\n";
echo julia_iters(-0.75, 0.15), "\n";
