--TEST--
unset() on an array with an integer key (issue #2273)
--FILE--
<?php
$a = [0 => 1, 1 => 2];
unset($a[0]);
echo isset($a[0]) ? "y" : "n", "\n";
echo $a[1], "\n";
--EXPECT--
n
2
