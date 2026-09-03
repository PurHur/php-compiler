<?php

declare(strict_types=1);

/**
 * Spectral-norm (scaled) — nested float loops (#36385).
 */

function a(int $i, int $j): float
{
    return 1.0 / ((float) ((($i + $j) * ($i + $j + 1) >> 1) + $i + 1));
}

function times(int $n, array $u): array
{
    $v = [];
    for ($i = 0; $i < $n; ++$i) {
        $sum = 0.0;
        for ($j = 0; $j < $n; ++$j) {
            $sum += a($i, $j) * (float) $u[$j];
        }
        $v[$i] = $sum;
    }

    return $v;
}

function timesTransposed(int $n, array $u): array
{
    $v = [];
    for ($i = 0; $i < $n; ++$i) {
        $sum = 0.0;
        for ($j = 0; $j < $n; ++$j) {
            $sum += a($j, $i) * (float) $u[$j];
        }
        $v[$i] = $sum;
    }

    return $v;
}

function aTimesTransp(int $n, array $u): array
{
    return timesTransposed($n, times($n, $u));
}

$n = 40;
$u = [];
for ($i = 0; $i < $n; ++$i) {
    $u[$i] = 1.0;
}
$v = $u;
for ($i = 0; $i < 8; ++$i) {
    $v = aTimesTransp($n, $u);
    $u = aTimesTransp($n, $v);
}

$vBv = 0.0;
$vv = 0.0;
for ($i = 0; $i < $n; ++$i) {
    $vBv += (float) $u[$i] * (float) $v[$i];
    $vv += (float) $v[$i] * (float) $v[$i];
}
printf("%.9f\n", sqrt($vBv / $vv));
