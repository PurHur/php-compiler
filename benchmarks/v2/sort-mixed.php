<?php

declare(strict_types=1);

/**
 * Mixed sort — ints + strings (#36385).
 */

$n = 8000;
$ints = [];
$strs = [];
for ($i = 0; $i < $n; ++$i) {
    $ints[] = ($i * 1103515245 + 12345) & 0x7fffffff;
    $strs[] = 's'.(($i * 17) % 9973);
}

sort($ints);
sort($strs);

echo $ints[0], '|', $ints[$n - 1], '|', $strs[0], '|', $strs[$n - 1], "\n";
