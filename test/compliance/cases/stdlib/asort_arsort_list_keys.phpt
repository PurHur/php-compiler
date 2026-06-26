--TEST--
stdlib asort()/arsort() preserve numeric keys on packed lists (#11991, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = [3, 1, 2];
asort($a);
var_export($a);
echo "\n";
$b = [3, 1, 2];
arsort($b);
var_export($b);
echo "\n";
--EXPECT--
array (
  1 => 1,
  2 => 2,
  0 => 3,
)
array (
  0 => 3,
  2 => 2,
  1 => 1,
)
