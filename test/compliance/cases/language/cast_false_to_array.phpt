--TEST--
Language: (array) cast on false wraps [false] — Zend convert_to_array (#30097)
--FILE--
<?php
var_export((array) false);
echo "\n";
var_export((array) true);
echo "\n";
var_export((array) null);
echo "\n";
var_export((array) 1);
echo "\n";
var_export((array) 1.5);
echo "\n";
var_export((array) 'x');
echo "\n";
--EXPECT--
array (
  0 => false,
)
array (
  0 => true,
)
array (
)
array (
  0 => 1,
)
array (
  0 => 1.5,
)
array (
  0 => 'x',
)
