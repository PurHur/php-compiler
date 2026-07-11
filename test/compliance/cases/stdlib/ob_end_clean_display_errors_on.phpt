--TEST--
stdlib ob_end_clean() no buffer emits Notice when display_errors=1 (#13486, ext/standard/output.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
var_export(ob_end_clean());
echo "\n";
--EXPECTF--
PHP Notice:  ob_end_clean(): Failed to delete buffer. No buffer to delete%s
false
