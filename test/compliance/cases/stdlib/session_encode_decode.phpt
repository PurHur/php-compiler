--TEST--
stdlib session_encode() / session_decode() round-trip (#6086, ext/session/session.c)
--FILE--
<?php
session_start();
$_SESSION['k'] = 'v';
$blob = session_encode();
session_unset();
session_decode($blob);
echo (isset($_SESSION['k']) && $_SESSION['k'] === 'v') ? "OK\n" : "FAIL\n";
echo $blob, "\n";
--EXPECT--
OK
k|s:1:"v";
