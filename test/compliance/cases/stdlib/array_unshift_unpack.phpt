--TEST--
stdlib array_unshift() call-time unpack (php-src ext/standard/array.c, #6689)
--FILE--
<?php
$a = array(1, 2);
array_unshift($a, ...array(3, 4));
var_export($a);
echo "\n";
--EXPECT--
array (
  0 => 3,
  1 => 4,
  2 => 1,
  3 => 2,
)
