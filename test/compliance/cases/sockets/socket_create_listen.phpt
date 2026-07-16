--TEST--
stdlib socket_create_listen() INADDR_ANY + accept loopback (#6212, ext/sockets/sockets.c)
--FILE--
<?php
echo 'exists=', (int) function_exists('socket_create_listen'), "\n";

$port = 0;
$server = false;
for ($p = 56600; $p < 56620; ++$p) {
    $server = @socket_create_listen($p, 1);
    if (false !== $server) {
        $port = $p;
        break;
    }
}
if (false === $server) {
    fwrite(STDERR, "create_listen fail\n");
    exit(1);
}
echo 'class=', $server instanceof Socket ? 'Socket' : 'other', "\n";

$client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (!socket_connect($client, '127.0.0.1', $port)) {
    fwrite(STDERR, "connect fail\n");
    exit(1);
}
$peer = socket_accept($server);
echo 'accept=', $peer instanceof Socket ? 'Socket' : 'other', "\n";
socket_write($client, 'ping');
echo 'got=', socket_read($peer, 16), "\n";

socket_close($peer);
socket_close($client);
socket_close($server);
echo "done\n";
--EXPECT--
exists=1
class=Socket
accept=Socket
got=ping
done
