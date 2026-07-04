--TEST--
AOT: array_slice() nested array_search offset (#13684)
--FILE--
<?php
declare(strict_types=1);
$slice = array_slice([1, 2, 3, 4], array_search(3, [1, 2, 3, 4]));
var_export($slice);
echo "\n";
--EXPECT--
array (
  0 => 3,
  1 => 4,
)
