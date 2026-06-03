--TEST--
language: false array keys coerce to int 0 (issue #5275, zend_hash.c)
--FILE--
<?php
$a = [1, 2];
$a[false] = 3;
var_export($a);
echo "\n";

$b = ['a' => 1];
var_export($b[false]);
echo "\n";

$c = [];
$c[true] = 't';
var_export($c);
echo "\n";
--EXPECT--
array (
  0 => 3,
  1 => 2,
)
NULL
array (
  1 => 't',
)
