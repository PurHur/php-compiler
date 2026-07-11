--TEST--
stdlib array_column() inline haystack + null column_key + index_key (#15914)
--FILE--
<?php
declare(strict_types=1);
var_export(array_column([['x' => 1, 'y' => 2]], null, 'x'));
echo "\n";
var_export(array_column([['x' => 1], ['x' => 2]], null, 'x'));
echo "\n";
--EXPECT--
array (
  1 => array (
    'x' => 1,
    'y' => 2,
  ),
)
array (
  1 => array (
    'x' => 1,
  ),
  2 => array (
    'x' => 2,
  ),
)
