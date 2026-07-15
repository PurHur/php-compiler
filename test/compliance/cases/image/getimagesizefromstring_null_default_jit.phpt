--TEST--
image getimagesizefromstring(null) — E_NOTICE before false on default profile JIT (#19067, ext/standard/image.c)
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
$result = @getimagesizefromstring(null);
$last = error_get_last();
var_export($result);
echo "\n";
var_export($last['type'] ?? null);
echo "\n";
echo str_contains($last['message'] ?? '', 'Error reading from !') ? 'notice_ok' : 'notice_fail';
echo "\n";
?>
--EXPECT--
false
8
notice_ok
