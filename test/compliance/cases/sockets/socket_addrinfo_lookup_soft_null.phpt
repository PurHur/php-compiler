--TEST--
stdlib socket_addrinfo_lookup(null) soft-null Deprecated+false (#30337, ext/sockets/sockets.c)
--FILE--
<?php
error_reporting(E_ALL);
try {
    var_export(socket_addrinfo_lookup(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECTF--
PHP Deprecated:  socket_addrinfo_lookup(): Passing null to parameter #1 ($host) of type string is deprecated in %s on line %d
false
