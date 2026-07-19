--TEST--
stdlib stream_socket_get_name() — UDP bind address (issue #21009, ext/standard/streamsfuncs.c)
--FILE--
<?php
declare(strict_types=1);
$srv = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
if (false === $srv) {
    echo "server-fail\n";
    exit(1);
}
$name = stream_socket_get_name($srv, false);
echo is_string($name) && str_starts_with($name, '127.0.0.1:') ? '1' : '0', "\n";
// TCP path still works
$tcp = stream_socket_server('tcp://127.0.0.1:0');
$tcpName = stream_socket_get_name($tcp, false);
echo is_string($tcpName) && str_starts_with($tcpName, '127.0.0.1:') ? '1' : '0', "\n";
fclose($srv);
fclose($tcp);
--EXPECT--
1
1
