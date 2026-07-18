--TEST--
stdlib str_replace()/str_ireplace() null $search coerce on default profile (#20173, ext/standard/string.c)
--FILE--
<?php
error_reporting(E_ALL);
$n = null;
echo var_export(str_replace($n, 'b', 'hay'), true), "\n";
echo var_export(str_ireplace($n, 'b', 'Hay'), true), "\n";
?>
--EXPECTF--
PHP Deprecated:  str_replace(): Passing null to parameter #1 ($search) of type array|string is deprecated in %s on line %d
PHP Deprecated:  str_ireplace(): Passing null to parameter #1 ($search) of type array|string is deprecated in %s on line %d
'hay'
'Hay'
