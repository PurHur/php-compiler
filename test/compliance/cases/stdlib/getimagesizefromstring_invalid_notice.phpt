--TEST--
stdlib getimagesizefromstring('not-image') — E_NOTICE before false (#17961, ext/standard/image.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
$result = @getimagesizefromstring('not-image');
$last = error_get_last();
var_export($result);
echo "\n";
var_export($last['type'] ?? null);
echo "\n";
echo str_contains($last['message'] ?? '', 'Error reading from not-image!') ? 'notice_ok' : 'notice_fail';
echo "\n";
?>
--EXPECT--
false
8
notice_ok
