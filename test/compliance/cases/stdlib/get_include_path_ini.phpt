--TEST--
stdlib get_include_path/ini_get include_path — host ini seed (issue #10461)
--FILE--
<?php
$zendPath = ini_get('include_path');
echo get_include_path() === $zendPath ? "get_ok\n" : "get_bad\n";
echo ini_get('include_path') === $zendPath ? "ini_ok\n" : "ini_bad\n";
$old = set_include_path($zendPath . PATH_SEPARATOR . '/tmp/include_probe');
echo str_contains(get_include_path(), '/tmp/include_probe') ? "set_ok\n" : "set_bad\n";
restore_include_path();
echo get_include_path() === $zendPath ? "restore_ok\n" : "restore_bad\n";
--EXPECT--
get_ok
ini_ok
set_ok
restore_ok
