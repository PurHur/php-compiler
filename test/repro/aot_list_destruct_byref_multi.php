<?php

declare(strict_types=1);

/**
 * #34673 — multi-slot list by-ref destructuring must write through to the haystack under AOT.
 */
$a = [1, 2];
[&$x, &$y] = $a;
$x = 9;
echo $a[0], '|', $y, "\n";

$b = [3, 4];
list(&$u, $v) = $b;
$u = 7;
echo $b[0], "\n";
