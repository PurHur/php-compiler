--TEST--
AOT: array_column() object rows — public properties (#11236)
--FILE--
<?php
declare(strict_types=1);
var_export(array_column([(object) ['id' => 10], (object) ['id' => 20]], 'id'));
echo "\n";
--EXPECT--
array (
  0 => 10,
  1 => 20,
)
