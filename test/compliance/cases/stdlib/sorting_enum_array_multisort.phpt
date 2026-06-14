--TEST--
stdlib Sorting enum — array_multisort() ascending/descending (#7229)
--FILE--
<?php
var_export(enum_exists('Sorting', false));
echo "\n";
$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, Sorting::Ascending, $b);
echo implode(',', $a), "\n";
array_multisort($a, Sorting::Descending, $b);
echo implode(',', $a), "\n";
--EXPECT--
true
1,2,3
3,2,1
