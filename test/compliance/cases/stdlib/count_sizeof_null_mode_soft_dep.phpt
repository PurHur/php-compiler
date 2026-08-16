--TEST--
stdlib count/sizeof(null $mode) soft DEP+COUNT_NORMAL (#31463, Zend/zend_builtin_functions.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
echo count([1, 2], null), "\n";
echo sizeof([1, 2], null), "\n";
?>
--EXPECTF--
%ADeprecated: count(): Passing null to parameter #2 ($mode) of type int is deprecated in %s on line %d
2
%ADeprecated: sizeof(): Passing null to parameter #2 ($mode) of type int is deprecated in %s on line %d
2
