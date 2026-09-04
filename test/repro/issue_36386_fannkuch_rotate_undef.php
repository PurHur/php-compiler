<?php
declare(strict_types=1);
function f(int $n): int {
    $perm1 = [];
    for ($i = 0; $i < $n; ++$i) {
        $perm1[$i] = $i;
    }
    $r = 1;
    $p0 = $perm1[0];
    $i = 0;
    while ($i < $r) {
        $j = $i + 1;
        $perm1[$i] = $perm1[$j];
        $i = $j;
    }
    $perm1[$r] = $p0;
    return $i;
}
echo f(3), "\n";
