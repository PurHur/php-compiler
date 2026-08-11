--TEST--
stdlib socket_bind(null) soft-null Deprecated+Host lookup+false (#30315, ext/sockets/sockets.c)
--FILE--
<?php
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    var_export(socket_bind($s, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECTF--
PHP Deprecated:  socket_bind(): Passing null to parameter #2 ($address) of type string is deprecated in %s on line %d
PHP Warning:  socket_bind(): Host lookup failed [-10004]: No address associated with name in %s on line %d
false
