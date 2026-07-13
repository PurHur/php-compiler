--TEST--
stdlib var_export() on COW-split array alias (#5264, ext/standard/var.c)
--FILE--
<?php
$a = [1, 2];
$b = $a;
$b[] = 3;
var_export($a);
echo "\n";
var_export($b);
echo "\n";
--EXPECT--
array (
  0 => 1,
  1 => 2,
)
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
