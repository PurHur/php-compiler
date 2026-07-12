--TEST--
filter filter_var() FILTER_SANITIZE_STRING — E_DEPRECATED on use (#18105, ext/filter/filter.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
@filter_var('test', FILTER_SANITIZE_STRING);
$last = error_get_last();
var_export($last['type'] ?? null);
echo "\n";
echo str_contains($last['message'] ?? '', 'Constant FILTER_SANITIZE_STRING is deprecated') ? 'dep_ok' : 'dep_fail';
echo "\n";
?>
--EXPECT--
8192
dep_ok
