--TEST--
stdlib array_slice() with negative offset
--FILE--
<?php
$a = array(10, 20, 30);
$s = array_slice($a, 0 - 2);
echo count($s), "\n";
echo $s[0], "\n";
echo $s[1], "\n";
--EXPECT--
2
20
30
