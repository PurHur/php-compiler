--TEST--
language (array) cast on stream resource — one-element array with resource slot (#15012, Zend/zend_operators.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
var_export((array) $h);
echo "\n";
fclose($h);
var_export((array) $h);
echo "\n";
--EXPECT--
array (
  0 => NULL,
)
array (
  0 => NULL,
)
