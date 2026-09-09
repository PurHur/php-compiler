<?php

declare(strict_types=1);

/**
 * Large packed sort must not SIGSEGV under thin AOT (#36385).
 *
 * implementSortPacked used to alloca swap scratch inside the bubble-sort walk —
 * O(n²) stack growth; n≈1000 overflows the default 8 MiB stack.
 *
 * php-src: ext/standard/array.c php_array_sort / zend_sort.
 */
$n = 1500;
$ints = [];
$strs = [];
for ($i = 0; $i < $n; ++$i) {
    $ints[] = ($i * 1103515245 + 12345) & 0x7fffffff;
    $strs[] = 's'.(($i * 17) % 9973);
}

sort($ints);
sort($strs);

echo $ints[0], '|', $ints[$n - 1], '|', $strs[0], '|', $strs[$n - 1], "\n";
