--TEST--
language: array float key write coercion (issue #5118, zend_hash parity)
--FILE--
<?php
$a = [];
$a[1.5] = 'x';
var_export($a);
echo "\n";
$a[2.9] = 'y';
var_export($a[2]);
echo "\n";
--EXPECT--
array (
  1 => 'x',
)
'y'
