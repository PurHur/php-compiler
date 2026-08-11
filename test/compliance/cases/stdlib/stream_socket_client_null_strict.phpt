--TEST--
stdlib stream_socket_client(null) TypeError under strict_types (#30314, ext/standard/streamsfuncs.c)
--FILE--
<?php
declare(strict_types=1);
try {
    stream_socket_client(null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:stream_socket_client(): Argument #1 ($address) must be of type string, null given
