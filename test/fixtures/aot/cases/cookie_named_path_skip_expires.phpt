--TEST--
AOT: cookie named path skips expires_or_options (#24968)
--FILE--
<?php
error_reporting(E_ALL);
ob_start();
var_export(setcookie(name: 'n', value: 'v', path: '/'));
echo "\n";
var_export(setrawcookie(name: 'n', value: 'v', path: '/'));
echo "\n";
--EXPECT--
true
true
