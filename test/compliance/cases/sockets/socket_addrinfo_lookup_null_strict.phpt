--TEST--
stdlib socket_addrinfo_lookup(null) TypeError under strict_types (#30337, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    var_export(socket_addrinfo_lookup(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:socket_addrinfo_lookup(): Argument #1 ($host) must be of type string, null given
