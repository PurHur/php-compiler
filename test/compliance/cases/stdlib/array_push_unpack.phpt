--TEST--
stdlib array_push() call-time unpack (php-src ext/standard/array.c, #6689)
--FILE--
<?php
$a = array(1, 2);
array_push($a, ...array(3, 4));
var_export($a);
echo "\n";
--EXPECT--
array (
  0 => 1,
  1 => 2,
  2 => 3,
  3 => 4,
)
