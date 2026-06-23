--TEST--
stdlib ob_end_clean() no active buffer emits Notice (issue #10260, ext/standard/output.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
var_export(ob_get_level());
echo "\n";
$ok = ob_end_clean();
$last = error_get_last();
var_export($ok);
echo "\n";
var_export($last['message'] ?? null);
echo "\n";

ob_start();
echo 'x';
$active = ob_end_clean();
var_export($active);
echo "\n";
--EXPECT--
PHP Notice:  ob_end_clean(): Failed to delete buffer. No buffer to delete
0
false
'ob_end_clean(): Failed to delete buffer. No buffer to delete'
true
