--TEST--
stdlib usort() closure comparator JIT (issue #3597)
--FILE--
<?php
$a = [3, 1, 2];
usort($a, fn($x, $y) => $x <=> $y);
echo implode(',', $a), "\n";
--EXPECT--
1,2,3
