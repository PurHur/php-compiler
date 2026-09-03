<?php

declare(strict_types=1);

/**
 * Fannkuch-redux (scaled) — permutation / flip (#36385).
 */

function fannkuch(int $n): int
{
    $perm = [];
    $perm1 = [];
    $count = [];
    $maxFlips = 0;
    $r = $n;

    for ($i = 0; $i < $n; ++$i) {
        $perm1[$i] = $i;
        $count[$i] = $i;
    }

    while (true) {
        while ($r !== 1) {
            $count[$r - 1] = $r;
            --$r;
        }
        if (!($perm1[0] === 0 || $perm1[$n - 1] === $n - 1)) {
            for ($i = 0; $i < $n; ++$i) {
                $perm[$i] = $perm1[$i];
            }
            $flips = 0;
            $k = $perm[0];
            while ($k !== 0) {
                $k2 = (int) (($k + 1) / 2);
                for ($i = 0; $i < $k2; ++$i) {
                    $tmp = $perm[$i];
                    $perm[$i] = $perm[$k - $i];
                    $perm[$k - $i] = $tmp;
                }
                ++$flips;
                $k = $perm[0];
            }
            if ($flips > $maxFlips) {
                $maxFlips = $flips;
            }
        }

        while (true) {
            if ($r === $n) {
                return $maxFlips;
            }
            $p0 = $perm1[0];
            $i = 0;
            while ($i < $r) {
                $j = $i + 1;
                $perm1[$i] = $perm1[$j];
                $i = $j;
            }
            $perm1[$r] = $p0;
            $count[$r] = $count[$r] - 1;
            if ($count[$r] > 0) {
                break;
            }
            ++$r;
        }
    }
}

echo fannkuch(8), "\n";
