--TEST--
stdlib array_filter() inline closure callback (#12721, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
var_export(array_filter([1, 2, 3], fn (int $v): bool => $v > 1));
echo "\n";
--EXPECT--
array (
  1 => 2,
  2 => 3,
)
