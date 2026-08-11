--TEST--
stdlib socket_write(null) soft-null Deprecated+unable to write+false (#30320, ext/sockets/sockets.c)
--FILE--
<?php
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    var_export(socket_write($s, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECTF--
PHP Deprecated:  socket_write(): Passing null to parameter #2 ($data) of type string is deprecated in %s on line %d
PHP Warning:  socket_write(): unable to write to socket [32]: Broken pipe in %s on line %d
false
