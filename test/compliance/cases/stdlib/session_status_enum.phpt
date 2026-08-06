--TEST--
stdlib SessionStatus phantom absent; session_status() returns int (#28203, re-#7321)
--FILE--
<?php
ob_start();
var_export(enum_exists('SessionStatus', false));
echo "\n";
var_export(session_status());
echo "\n";
var_export(session_status() === PHP_SESSION_NONE);
echo "\n";
session_start();
var_export(session_status() === PHP_SESSION_ACTIVE);
echo "\n";
session_write_close();
var_export(session_status() === PHP_SESSION_NONE);
echo "\n";
--EXPECT--
false
1
true
true
true
