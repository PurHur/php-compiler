--TEST--
AOT: unserialize('') / empty var → false (#29483)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$empty = '';
echo var_export(unserialize($empty), true), "\n";
echo var_export(unserialize(''), true), "\n";
?>
--EXPECT--
false
false
