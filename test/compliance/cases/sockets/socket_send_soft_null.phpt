--TEST--
stdlib socket_send(null) soft-null Deprecated+Unable to write+false (#30321, ext/sockets/sockets.c)
--FILE--
<?php
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    var_export(socket_send($s, null, 0, 0));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECTF--
PHP Deprecated:  socket_send(): Passing null to parameter #2 ($data) of type string is deprecated in %s on line %d
PHP Warning:  socket_send(): Unable to write to socket [32]: Broken pipe in %s on line %d
false
