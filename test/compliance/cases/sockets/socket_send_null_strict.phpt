--TEST--
stdlib socket_send(null) TypeError under strict_types (#30321, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    var_export(socket_send($s, null, 0, 0));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:socket_send(): Argument #2 ($data) must be of type string, null given
