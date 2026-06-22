--TEST--
stdlib ob_end_flush() no active buffer emits Notice (issue #10536, ext/standard/output.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
var_export(ob_get_level());
echo "\n";
$ok = ob_end_flush();
$last = error_get_last();
var_export($ok);
echo "\n";
var_export($last['message'] ?? null);
echo "\n";

ob_start();
echo 'x';
$active = ob_end_flush();
var_export($active);
echo "\n";
--EXPECT--
0
false
'ob_end_flush(): Failed to delete and flush buffer. No buffer to delete or flush'
xtrue
