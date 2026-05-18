--TEST--
stdlib array_fill() packed indices
--FILE--
<?php
$a = array_fill(1, 3, 7);
echo count($a), "\n";
echo $a[1], '|', $a[2], '|', $a[3], "\n";
--EXPECT--
3
7|7|7
