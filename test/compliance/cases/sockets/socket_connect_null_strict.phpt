--TEST--
stdlib socket_connect(null) TypeError under strict_types cites $address (#30316, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    var_export(socket_connect($s, null, 80));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:socket_connect(): Argument #2 ($address) must be of type string, null given
