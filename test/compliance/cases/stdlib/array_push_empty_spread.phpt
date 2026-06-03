--TEST--
stdlib array_push() empty spread (php-src ext/standard/array.c)
--FILE--
<?php
$a = array(1, 2);
$empty = array();
echo array_push($a, ...$empty), "\n";
echo count($a), "\n";
echo array_push($a, 3, ...$empty), "\n";
echo count($a), "\n";
--EXPECT--
2
2
3
3
