--TEST--
stdlib socket_atmark() — withheld on PHP 8.2 profile (#25874, ext/sockets/sockets.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo function_exists('socket_atmark') ? "fail\n" : "ok\n";
echo extension_loaded('sockets') ? "sockets_ok\n" : "sockets_fail\n";
--EXPECT--
ok
sockets_ok
