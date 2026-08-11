--TEST--
stdlib socket_create_pair(null) Deprecated+ValueError domain (#30338, ext/sockets/sockets.c)
--FILE--
<?php
error_reporting(E_ALL);
$fds = null;
try {
    var_export(socket_create_pair(null, SOCK_STREAM, 0, $fds));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECTF--
PHP Deprecated:  socket_create_pair(): Passing null to parameter #1 ($domain) of type int is deprecated in %s on line %d

ValueError:socket_create_pair(): Argument #1 ($domain) must be one of AF_UNIX, AF_INET6, or AF_INET
