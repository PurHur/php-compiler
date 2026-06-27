--TEST--
stdlib ob_get_flush() no active buffer emits Notice (issue #12890, ext/standard/output.c)
--FILE--
<?php
error_reporting(E_ALL);
var_export(ob_get_level());
echo "\n";
$ok = ob_get_flush();
$last = error_get_last();
var_export($ok);
echo "\n";
var_export($last['message'] ?? null);
echo "\n";

ob_start();
echo 'y';
$active = ob_get_flush();
var_export($active);
echo "\n";
--EXPECT--
PHP Notice:  ob_get_flush(): Failed to delete and flush buffer. No buffer to delete or flush in - on line 5
0
false
'ob_get_flush(): Failed to delete and flush buffer. No buffer to delete or flush'
y'y'
