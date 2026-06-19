--TEST--
language (string) cast on NAN yields 'NAN' (#10143, Zend/zend_operators.c)
--FILE--
<?php
var_export((string) NAN);
echo "\n";
var_export((string) INF);
echo "\n";
var_export((string) -INF);
echo "\n";
$x = NAN;
var_export((string) $x);
echo "\n";
settype($x, 'string');
var_export($x);
echo "\n";
--EXPECT--
'NAN'
'INF'
'-INF'
'NAN'
'NAN'
