--TEST--
stdlib var_export() inline array with FuncCall element (#15783, ext/standard/var.c)
--FILE--
<?php
var_export([0, strlen('x')]);
echo "\n";
var_export([true, strlen('ab')]);
echo "\n";
$dt = new DateTime('2020-01-01');
var_export([$dt instanceof DateTime, $dt->format('Y-m-d')]);
echo "\n";
--EXPECT--
array (
  0 => 0,
  1 => 1,
)
array (
  0 => true,
  1 => 2,
)
array (
  0 => true,
  1 => '2020-01-01',
)
