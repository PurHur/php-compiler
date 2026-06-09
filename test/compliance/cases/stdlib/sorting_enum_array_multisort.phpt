--TEST--
stdlib Sorting enum — array_multisort() ascending/descending (#7229)
--FILE--
<?php
var_export(enum_exists('Sorting', false));
echo "\n";
$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, $b, Sorting::Ascending);
echo implode(',', $a), "\n";
array_multisort($a, $b, Sorting::Descending);
echo implode(',', $b), "\n";
--EXPECT--
true
1,2,3
c,b,a
