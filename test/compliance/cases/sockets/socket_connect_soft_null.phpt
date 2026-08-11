--TEST--
stdlib socket_connect(null) soft-null Deprecated+Host lookup+false (#30316, ext/sockets/sockets.c)
--FILE--
<?php
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    var_export(socket_connect($s, null, 80));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECTF--
PHP Deprecated:  socket_connect(): Passing null to parameter #2 ($address) of type string is deprecated in %s on line %d
PHP Warning:  socket_connect(): Host lookup failed [-10004]: No address associated with name in %s on line %d
false
