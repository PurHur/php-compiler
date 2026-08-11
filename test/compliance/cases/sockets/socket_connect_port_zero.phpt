--TEST--
stdlib socket_connect AF_INET explicit port 0 still attempts connect (#30339, ext/sockets/sockets.c)
--FILE--
<?php
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    var_export(socket_connect($s, '127.0.0.1', 0));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECTF--
PHP Warning:  socket_connect(): unable to connect [%d]: %s in %s on line %d
false
