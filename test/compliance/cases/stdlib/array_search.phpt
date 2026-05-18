--TEST--
stdlib array_search() loose comparison
--FILE--
<?php
$a = array('a' => 1, 'b' => 2, 'c' => 1);
echo array_search(2, $a), "\n";
echo array_search(1, $a), "\n";
echo array_search(9, $a) === false ? 'y' : 'n', "\n";
--EXPECT--
b
a
y
