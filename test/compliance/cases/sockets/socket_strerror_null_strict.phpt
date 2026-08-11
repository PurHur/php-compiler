--TEST--
stdlib socket_strerror(null) TypeError under strict_types (#30266, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);
try {
    socket_strerror(null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:socket_strerror(): Argument #1 ($error_code) must be of type int, null given
