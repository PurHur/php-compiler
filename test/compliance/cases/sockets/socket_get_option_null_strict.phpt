--TEST--
stdlib socket_get_option/set_option(null) TypeError under strict_types (#30265, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    socket_get_option($s, null, SO_REUSEADDR);
    echo "fail_get_level\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    socket_get_option($s, SOL_SOCKET, null);
    echo "fail_get_option\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    socket_set_option($s, null, SO_REUSEADDR, 1);
    echo "fail_set_level\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    socket_setopt($s, SOL_SOCKET, null, 1);
    echo "fail_setopt_option\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:socket_get_option(): Argument #2 ($level) must be of type int, null given
TypeError:socket_get_option(): Argument #3 ($option) must be of type int, null given
TypeError:socket_set_option(): Argument #2 ($level) must be of type int, null given
TypeError:socket_setopt(): Argument #3 ($option) must be of type int, null given
