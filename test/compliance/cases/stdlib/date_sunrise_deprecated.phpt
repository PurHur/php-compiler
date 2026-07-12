--TEST--
stdlib date_sunrise()/date_sunset() — E_DEPRECATED on call (#18109, ext/date/php_date.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
@date_sunrise(time());
$rise = error_get_last();
var_export($rise['type'] ?? null);
echo "\n";
echo str_contains($rise['message'] ?? '', 'Function date_sunrise() is deprecated') ? 'sunrise_ok' : 'sunrise_fail';
echo "\n";
@date_sunset(time());
$set = error_get_last();
var_export($set['type'] ?? null);
echo "\n";
echo str_contains($set['message'] ?? '', 'Function date_sunset() is deprecated') ? 'sunset_ok' : 'sunset_fail';
echo "\n";
?>
--EXPECT--
8192
sunrise_ok
8192
sunset_ok
