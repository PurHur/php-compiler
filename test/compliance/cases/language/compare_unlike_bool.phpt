--TEST--
Unlike-kind spaceship/relational: array/object/resource vs bool (#29629, zend_operators.c)
--FILE--
<?php
var_export([] <=> false);
echo "\n";
var_export([1] <=> false);
echo "\n";
var_export([] < true);
echo "\n";
var_export((new stdClass) <=> true);
echo "\n";
$fp = fopen('php://memory', 'r');
var_export($fp <=> false);
echo "\n";
var_export($fp <=> true);
echo "\n";
var_export((new stdClass) < false);
echo "\n";
var_export([1] >= false);
echo "\n";
--EXPECT--
0
1
true
0
1
0
false
true
