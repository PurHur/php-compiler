--TEST--
stdlib stream_socket_server bind failure Unable-to-connect (+ getaddrinfo) Warning (#30395)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_WARNING !== $no) {
        return false;
    }
    if (str_starts_with($msg, 'stream_socket_server(): php_network_getaddresses:')) {
        echo "GETADDR\n";
        return true;
    }
    if (str_contains($msg, 'Unable to connect') && str_contains($msg, 'php_network_getaddresses:')) {
        echo "WARN_GETADDR\n";
        return true;
    }
    if (str_contains($msg, 'Unable to connect') && str_contains($msg, 'Address already in use')) {
        echo "WARN_INUSE\n";
        return true;
    }
    if (str_contains($msg, 'Unable to connect') && str_contains($msg, 'Unknown error')) {
        echo "WARN_UNKNOWN\n";
        return true;
    }
    echo "WARN_OTHER\n";
    return true;
});

try {
    var_export(stream_socket_server('tcp://256.256.256.256:1'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

$first = @stream_socket_server('tcp://127.0.0.1:0');
if (false === $first) {
    echo "SKIP_INUSE\n";
} else {
    $name = stream_socket_get_name($first, false);
    try {
        var_export(stream_socket_server('tcp://' . $name));
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
    fclose($first);
}

try {
    var_export(stream_socket_server('unix:///tmp/no-such-dir-xyz-30395/sss.sock'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
GETADDR
WARN_GETADDR
false
WARN_INUSE
false
WARN_UNKNOWN
false
