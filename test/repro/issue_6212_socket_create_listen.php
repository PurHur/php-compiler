<?php
// Issue #6212 — socket_create_listen() AF_INET INADDR_ANY + accept loopback
echo 'exists=', (int) function_exists('socket_create_listen'), "\n";

$port = 0;
$server = false;
for ($p = 56500; $p < 56520; ++$p) {
    $server = @socket_create_listen($p, 1);
    if (false !== $server) {
        $port = $p;
        break;
    }
}
if (false === $server) {
    echo "create_listen_fail\n";
    exit(1);
}
echo 'class=', $server instanceof Socket ? 'Socket' : 'other', "\n";

$client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (!socket_connect($client, '127.0.0.1', $port)) {
    echo "connect_fail\n";
    exit(1);
}
$peer = socket_accept($server);
echo 'accept=', $peer instanceof Socket ? 'Socket' : 'other', "\n";
socket_write($client, 'hi');
echo 'read=', socket_read($peer, 16), "\n";

socket_close($peer);
socket_close($client);
socket_close($server);
echo "ok\n";
