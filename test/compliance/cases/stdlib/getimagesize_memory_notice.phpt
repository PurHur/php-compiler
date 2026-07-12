--TEST--
stdlib getimagesize(php://memory) — E_NOTICE wrapper read not E_WARNING ENOENT (#18405, ext/standard/image.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
$result = @getimagesize('php://memory');
$last = error_get_last();
var_export($result);
echo "\n";
var_export($last['type'] ?? null);
echo "\n";
echo str_contains($last['message'] ?? '', 'Error reading from php://memory!') ? 'notice_ok' : 'notice_fail';
echo "\n";
?>
--EXPECT--
false
8
notice_ok
