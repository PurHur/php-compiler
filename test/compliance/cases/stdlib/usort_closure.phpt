--TEST--
stdlib usort() closure comparator on values (issue #3597, #3086)
--FILE--
<?php
$a = [3, 1, 2];
usort($a, fn($x, $y) => $x <=> $y);
echo implode(',', $a), "\n";
--EXPECT--
1,2,3
