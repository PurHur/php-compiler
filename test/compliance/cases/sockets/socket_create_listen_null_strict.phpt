--TEST--
stdlib socket_create_listen(null) TypeError under strict_types (#30264, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);
try {
    socket_create_listen(null);
    echo "fail_port\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    socket_create_listen(0, null);
    echo "fail_backlog\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:socket_create_listen(): Argument #1 ($port) must be of type int, null given
TypeError:socket_create_listen(): Argument #2 ($backlog) must be of type int, null given
