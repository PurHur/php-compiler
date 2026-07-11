--TEST--
stdlib ob_flush() no active buffer emits Notice (issue #12890, ext/standard/output.c)
--FILE--
<?php
error_reporting(E_ALL);
var_export(ob_get_level());
echo "\n";
$ok = ob_flush();
$last = error_get_last();
var_export($ok);
echo "\n";
var_export($last['message'] ?? null);
echo "\n";

ob_start();
echo 'x';
ob_flush();
var_export(ob_get_level());
echo "\n";
--EXPECT--
PHP Notice:  ob_flush(): Failed to flush buffer. No buffer to flush in - on line 5
0
false
'ob_flush(): Failed to flush buffer. No buffer to flush'
x1
