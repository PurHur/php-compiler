--TEST--
stdlib socket_bind(null) TypeError under strict_types (#30315, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    var_export(socket_bind($s, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:socket_bind(): Argument #2 ($address) must be of type string, null given
