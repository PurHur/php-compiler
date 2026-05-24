--TEST--
foreach by-reference mutates array values (#1222)
--FILE--
<?php
$a = [1, 2, 3];
foreach ($a as &$v) {
    $v = 10;
}
unset($v);
echo implode(',', $a), "\n";
$b = ['x' => 1, 'y' => 2];
foreach ($b as &$val) {
    $val = 6;
}
unset($val);
echo implode(',', array_values($b)), "\n";
--EXPECT--
10,10,10
6,6
