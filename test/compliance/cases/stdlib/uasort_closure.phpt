--TEST--
stdlib uasort() closure comparator on values (issue #3582, #3086)
--FILE--
<?php
$a = [1 => 1.5, 2 => 2.5, 0 => 0.5];
uasort($a, fn ($x, $y) => $x <=> $y);
echo implode(',', array_values($a)), "\n";
echo implode(',', array_keys($a)), "\n";
--EXPECT--
0.5,1.5,2.5
0,1,2
