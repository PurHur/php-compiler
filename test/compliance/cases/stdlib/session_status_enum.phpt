--TEST--
stdlib SessionStatus enum for session_status() (#7321, ext/session/session.c)
--FILE--
<?php
var_export(enum_exists('SessionStatus', false));
echo "\n";
var_export(SessionStatus::None->name);
echo "\n";
var_export(SessionStatus::Active->value);
echo "\n";
var_export(session_status());
echo "\n";
var_export(session_status() === PHP_SESSION_NONE);
echo "\n";
var_export(session_status() === SessionStatus::None);
echo "\n";
session_start();
var_export(session_status() === PHP_SESSION_ACTIVE);
echo "\n";
session_write_close();
var_export(session_status() === PHP_SESSION_NONE);
echo "\n";
--EXPECT--
true
'None'
2
1
true
false
true
true
