--TEST--
AOT getimagesize(data://…) — E_NOTICE image read (#12931)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
$uri = 'data://text/plain,not';
$result = @getimagesize($uri);
$last = error_get_last();
var_export($result);
echo "\n";
var_export($last['type'] ?? null);
echo "\n";
echo str_contains($last['message'] ?? '', 'Error reading from data://text/plain,not!') ? 'notice_ok' : 'notice_fail';
echo "\n";
?>
--EXPECT--
false
8
notice_ok
