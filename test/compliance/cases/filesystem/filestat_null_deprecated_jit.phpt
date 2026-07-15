--TEST--
stdlib file_exists()/is_writable() null — E_DEPRECATED JIT (#18765, ext/standard/filestat.c)
--FILE--
<?php
error_reporting(E_ALL);
echo var_export(file_exists(null)), "\n";
echo var_export(is_writable(null)), "\n";
?>
--EXPECTF--
PHP Deprecated:  file_exists(): Passing null to parameter #1 ($filename) of type string is deprecated in %s on line %d
PHP Deprecated:  is_writable(): Passing null to parameter #1 ($filename) of type string is deprecated in %s on line %d
false
false
