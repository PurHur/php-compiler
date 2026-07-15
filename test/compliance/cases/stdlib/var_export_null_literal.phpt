--TEST--
stdlib var_export(null) literal — compile-time null operand must output NULL (#19066, ext/standard/var.c)
--FILE--
<?php
ob_start();
var_export(null);
echo ob_get_clean(), "\n";
$v = null;
ob_start();
var_export($v);
echo ob_get_clean(), "\n";
--EXPECT--
NULL
NULL
