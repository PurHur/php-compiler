--TEST--
stdlib socket_sendto(null) soft-null data/address (#30319, ext/sockets/sockets.c)
--FILE--
<?php
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
try {
    var_export(socket_sendto($s, 'x', 1, 0, null, 53));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(socket_sendto($s, null, 0, 0, '127.0.0.1', 53));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECTF--
PHP Deprecated:  socket_sendto(): Passing null to parameter #5 ($address) of type string is deprecated in %s on line %d
PHP Warning:  socket_sendto(): Host lookup failed [-10004]: No address associated with name in %s on line %d
false
PHP Deprecated:  socket_sendto(): Passing null to parameter #2 ($data) of type string is deprecated in %s on line %d
0
