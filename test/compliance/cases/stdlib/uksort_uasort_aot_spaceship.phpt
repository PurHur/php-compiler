--TEST--
stdlib uksort()/uasort() spaceship arrow under AOT (issue #27217)
--FILE--
<?php
$a = ['b' => 1, 'a' => 2, 'c' => 0];
uksort($a, fn ($x, $y) => $x <=> $y);
echo implode(',', array_keys($a)), "\n";
$b = ['b' => 2, 'a' => 1, 'c' => 3];
uasort($b, fn ($x, $y) => $x <=> $y);
echo implode(',', $b), "\n";
--EXPECT--
a,b,c
1,2,3
