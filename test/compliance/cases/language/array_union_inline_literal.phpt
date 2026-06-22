--TEST--
language array union + on inline array literals (#10490, #10578, Zend/zend_operators.c)
--FILE--
<?php
var_export([1 => 'a'] + [2 => 'b']);
echo "\n";
var_export(['a' => 1] + ['a' => 2]);
echo "\n";
var_export([] + [1]);
echo "\n";
?>
--EXPECT--
array (
  1 => 'a',
  2 => 'b',
)
array (
  'a' => 1,
)
array (
  0 => 1,
)
