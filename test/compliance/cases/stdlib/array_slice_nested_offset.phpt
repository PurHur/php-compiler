--TEST--
stdlib array_slice() nested int-returning builtin offset (#13684)
--FILE--
<?php
declare(strict_types=1);
$slice = array_slice([1, 2, 3, 4], array_search(3, [1, 2, 3, 4]));
var_export($slice);
echo "\n";
$off = array_search(3, [1, 2, 3, 4]);
var_export(array_slice([1, 2, 3, 4], $off));
echo "\n";
--EXPECT--
array (
  0 => 3,
  1 => 4,
)
array (
  0 => 3,
  1 => 4,
)
