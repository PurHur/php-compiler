--TEST--
stdlib stream_socket_get_name() — TCP bind address (issue #12223, ext/standard/streamsfuncs.c)
--FILE--
<?php
declare(strict_types=1);
echo function_exists('stream_socket_get_name') ? '1' : '0', "\n";
$srv = stream_socket_server('tcp://127.0.0.1:0');
if (false === $srv) {
    echo "server-fail\n";
    exit(1);
}
$name = stream_socket_get_name($srv, false);
echo is_string($name) && str_starts_with($name, '127.0.0.1:') ? '1' : '0', "\n";
fclose($srv);
--EXPECT--
1
1
