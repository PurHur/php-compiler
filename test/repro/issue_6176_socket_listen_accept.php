<?php
declare(strict_types=1);

// Issue #6176 — socket server API: set_option/get_option + listen/accept loopback
$funcs = ['socket_bind', 'socket_listen', 'socket_accept', 'socket_set_option', 'socket_get_option'];
foreach ($funcs as $f) {
    if (!function_exists($f)) {
        echo "missing:$f\n";
        exit(1);
    }
}

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (false === $server) {
    echo "create_fail\n";
    exit(1);
}
$ok = socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
echo 'setopt=', (int) $ok, "\n";
$got = socket_get_option($server, SOL_SOCKET, SO_REUSEADDR);
echo 'getopt=', var_export($got, true), "\n";

$port = 0;
for ($p = 49152; $p < 49252; ++$p) {
    if (@socket_bind($server, '127.0.0.1', $p)) {
        $port = $p;
        break;
    }
}
if (0 === $port) {
    echo "bind_fail\n";
    exit(1);
}
echo 'bound=', $port, "\n";
if (!socket_listen($server, 1)) {
    echo "listen_fail\n";
    exit(1);
}

$client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (!socket_connect($client, '127.0.0.1', $port)) {
    echo "connect_fail\n";
    exit(1);
}
$peer = socket_accept($server);
echo 'accept=', $peer instanceof Socket ? 'Socket' : gettype($peer), "\n";

socket_write($client, 'hi');
$buf = socket_read($peer, 16);
echo 'read=', var_export($buf, true), "\n";

socket_close($peer);
socket_close($client);
socket_close($server);
echo "ok\n";
