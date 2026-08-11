--TEST--
stdlib socket_create_pair valid AF_UNIX still creates pair (#30338, ext/sockets/sockets.c)
--FILE--
<?php
error_reporting(E_ALL);
$fds = null;
var_export(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $fds));
echo "\n";
echo is_array($fds) ? count($fds) : gettype($fds), "\n";
echo isset($fds[0], $fds[1]) && $fds[0] instanceof Socket && $fds[1] instanceof Socket ? "ok\n" : "bad\n";
--EXPECT--
true
2
ok
