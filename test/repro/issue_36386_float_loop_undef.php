<?php

declare(strict_types=1);

/**
 * Repro: false Undefined variable on float mul ASSIGN dest inside a loop (#36386 / #36405).
 * Zend prints "0\n0\n" with empty stderr.
 */
function f(): void
{
    $zr = 0.0;
    for ($i = 0; $i < 2; ++$i) {
        $zr2 = $zr * $zr;
        echo $zr2, "\n";
    }
}
f();
