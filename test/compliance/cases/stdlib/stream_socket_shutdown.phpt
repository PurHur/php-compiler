--TEST--
stdlib stream_socket_shutdown() — TCP shutdown closes stream (issue #6043, ext/standard/streamsfuncs.c)
--FILE--
<?php
declare(strict_types=1);
echo function_exists('stream_socket_shutdown') ? '1' : '0', "\n";
$srv = stream_socket_server('tcp://127.0.0.1:0');
if (false === $srv) {
    echo "server-fail\n";
    exit(1);
}
$name = stream_socket_get_name($srv, false);
echo is_string($name) && str_starts_with($name, '127.0.0.1:') ? '1' : '0', "\n";
$ok = stream_socket_shutdown($srv, STREAM_SHUT_RDWR);
echo $ok ? '1' : '0', "\n";
echo feof($srv) ? '1' : '0', "\n";
--EXPECT--
1
1
1
1
