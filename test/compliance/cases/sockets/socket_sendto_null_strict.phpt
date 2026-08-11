--TEST--
stdlib socket_sendto(null) TypeError under strict_types (#30319, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
try {
    socket_sendto($s, 'x', 1, 0, null, 53);
    echo "fail_addr\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    socket_sendto($s, null, 0, 0, '127.0.0.1', 53);
    echo "fail_data\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:socket_sendto(): Argument #5 ($address) must be of type string, null given
TypeError:socket_sendto(): Argument #2 ($data) must be of type string, null given
