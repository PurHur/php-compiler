--TEST--
AOT: array_search() loose and strict comparison
--FILE--
<?php
$a = array('a' => 1, 'b' => 2, 'c' => 1);
echo array_search(2, $a), "\n";
echo array_search(1, $a), "\n";
echo array_search(9, $a) === false ? 'y' : 'n', "\n";
echo array_search('2', $a, true) === false ? 'y' : 'n', "\n";
$list = array(10, 20, 10);
echo array_search(20, $list), "\n";
--EXPECT--
b
a
y
y
1
