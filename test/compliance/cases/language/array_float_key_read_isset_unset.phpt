--TEST--
language: array float key read/isset/unset coercion (issue #5123, zend_hash parity)
--FILE--
<?php
$a = [1 => 'x'];
var_export($a[1.5]);
echo "\n";
var_export(isset($a[1.5]));
echo "\n";
unset($a[1.5]);
var_export($a);
echo "\n";

$b = ['k' => 'hashtable', 0 => 'zero', 2 => 'two'];
var_export($b[2.9]);
echo "\n";
var_export(isset($b[2.9]));
echo "\n";
unset($b[2.9]);
var_export(array_keys($b));
echo "\n";
--EXPECT--
'x'
true
array (
)
'two'
true
array (
  0 => 'k',
  1 => 0,
)
