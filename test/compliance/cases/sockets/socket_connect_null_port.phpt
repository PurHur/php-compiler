--TEST--
stdlib socket_connect AF_INET null port ValueError (#30339, ext/sockets/sockets.c)
--FILE--
<?php
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    var_export(socket_connect($s, '127.0.0.1', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
ValueError:socket_connect(): Argument #3 ($port) cannot be null when the socket type is AF_INET
