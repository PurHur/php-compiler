--TEST--
stdlib array_slice() with explicit null length (remainder)
--FILE--
<?php
$a = array(10, 20, 30, 40);
$s = array_slice($a, 1, null);
echo count($s), "\n";
echo $s[0], "\n";
echo $s[1], "\n";
echo $s[2], "\n";
--EXPECT--
3
20
30
40
