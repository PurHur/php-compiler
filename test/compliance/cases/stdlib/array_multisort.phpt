--TEST--
stdlib array_multisort() on string list arrays (VM, issue #1212)
--FILE--
<?php
$scores = ['3', '1', '2'];
$names = ['c', 'a', 'b'];
array_multisort($scores, $names);
echo implode(',', $scores), "\n";
echo implode(',', $names), "\n";
$nums = [30, 10, 20];
$labels = ['z', 'x', 'y'];
array_multisort($nums, $labels, 3);
echo implode(',', $nums), "\n";
echo implode(',', $labels), "\n";
--EXPECT--
1,2,3
a,b,c
30,20,10
z,y,x
