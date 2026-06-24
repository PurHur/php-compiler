--TEST--
stdlib array_column() object rows — public properties (#11236, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
var_export(array_column([(object) ['id' => 10], (object) ['id' => 20]], 'id'));
echo "\n";
var_export(array_column([['id' => 1], (object) ['id' => 2]], 'id'));
echo "\n";
--EXPECT--
array (
  0 => 10,
  1 => 20,
)
array (
  0 => 1,
  1 => 2,
)
