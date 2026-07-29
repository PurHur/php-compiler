--TEST--
stdlib setcookie/setrawcookie named path skips expires_or_options JIT (#24968)
--JIT--
--ENV--
GATEWAY_INTERFACE=CGI/1.1
--FILE--
<?php
error_reporting(E_ALL);
ob_start();
var_export(setcookie(name: 'n', value: 'v', path: '/'));
echo "\n";
var_export(setrawcookie(name: 'nr', value: 'vr', path: '/app'));
echo "\n";
--EXPECT--
true
true
