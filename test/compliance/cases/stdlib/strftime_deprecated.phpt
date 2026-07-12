--TEST--
stdlib strftime()/gmstrftime() — E_DEPRECATED on call (#18103, ext/standard/datetime.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
@strftime('%Y', time());
$last = error_get_last();
var_export($last['type'] ?? null);
echo "\n";
echo str_contains($last['message'] ?? '', 'Function strftime() is deprecated') ? 'strftime_ok' : 'strftime_fail';
echo "\n";
@gmstrftime('%Y', time());
$last = error_get_last();
var_export($last['type'] ?? null);
echo "\n";
echo str_contains($last['message'] ?? '', 'Function gmstrftime() is deprecated') ? 'gmstrftime_ok' : 'gmstrftime_fail';
echo "\n";
?>
--EXPECT--
8192
strftime_ok
8192
gmstrftime_ok
