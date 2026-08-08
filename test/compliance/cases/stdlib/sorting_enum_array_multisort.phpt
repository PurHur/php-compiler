--TEST--
stdlib Sorting phantom absent; array_multisort() SORT_* ints (#28930, re-#7229)
--FILE--
<?php
var_export(enum_exists('Sorting', false));
echo "\n";
$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, SORT_ASC, $b);
echo implode(',', $a), "\n";
array_multisort($a, SORT_DESC, $b);
echo implode(',', $a), "\n";
--EXPECT--
false
1,2,3
3,2,1
