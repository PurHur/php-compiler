--TEST--
stdlib ob_end_clean()/ob_end_flush() no buffer — stderr notice via php_error_cb (#13486, #13542, ext/standard/output.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
var_export(ob_get_level());
echo "\n";
ob_end_clean();
$last = error_get_last();
var_export($last['type'] ?? null);
echo "\n";
var_export($last['message'] ?? null);
echo "\n";
ob_end_flush();
$last2 = error_get_last();
var_export($last2['message'] ?? null);
echo "\n";
--EXPECT--
0
8
'ob_end_clean(): Failed to delete buffer. No buffer to delete'
'ob_end_flush(): Failed to delete and flush buffer. No buffer to delete or flush'
