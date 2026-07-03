--TEST--
stdlib array_merge_recursive() inline scalar key conflicts (#15552, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$r1 = array_merge_recursive(['a' => 1], ['a' => 2]);
$r2 = array_merge_recursive(['a' => ['x' => 1]], ['a' => ['y' => 2]]);
$r3 = array_merge_recursive(['a' => 1], ['a' => [2]]);
echo var_export($r1, true), "\n";
echo var_export($r2, true), "\n";
echo var_export($r3, true), "\n";
--EXPECT--
array (
  'a' => array (
    0 => 1,
    1 => 2,
  ),
)
array (
  'a' => array (
    'x' => 1,
    'y' => 2,
  ),
)
array (
  'a' => array (
    0 => 1,
    1 => 2,
  ),
)
