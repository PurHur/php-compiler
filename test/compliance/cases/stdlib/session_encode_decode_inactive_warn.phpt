--TEST--
stdlib session_encode()/session_decode() inactive session E_WARNING (#21952, ext/session/session.c)
--FILE--
<?php
ob_start();
var_export(session_encode());
echo '|';
var_export(session_decode('a|i:1;'));
echo "\n";
--EXPECTF--
PHP Warning:  session_encode(): Cannot encode non-existent session in %s on line %d
PHP Warning:  session_decode(): Session data cannot be decoded when there is no active session in %s on line %d
false|false
