--TEST--
stdlib session_commit() — alias of session_write_close() (#12544, ext/session/session.c)
--FILE--
<?php
var_export(function_exists('session_commit'));
echo "\n";
var_export(function_exists('session_write_close'));
echo "\n";
session_start();
$_SESSION['k'] = 'v';
session_commit();
echo isset($_SESSION['k']) && $_SESSION['k'] === 'v' ? "persist-ok\n" : "persist-bad\n";
--EXPECT--
true
true
persist-ok
