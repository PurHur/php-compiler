--TEST--
AOT stream_socket_server(null) TypeError under strict_types (#30374, ext/standard/streamsfuncs.c)
--FILE--
<?php
declare(strict_types=1);
try {
    stream_socket_server(null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:stream_socket_server(): Argument #1 ($address) must be of type string, null given
