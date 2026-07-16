--TEST--
stdlib socket_listen/accept + set/get_option SO_REUSEADDR loopback (#6176, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);

echo 'exists=', (int) (
    function_exists('socket_listen')
    && function_exists('socket_accept')
    && function_exists('socket_set_option')
    && function_exists('socket_get_option')
    && function_exists('socket_bind')
), "\n";

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
echo 'setopt=', (int) socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1), "\n";
echo 'getopt=', (int) socket_get_option($server, SOL_SOCKET, SO_REUSEADDR), "\n";

$port = 0;
for ($p = 49152; $p < 49252; ++$p) {
    if (@socket_bind($server, '127.0.0.1', $p)) {
        $port = $p;
        break;
    }
}
if (0 === $port) {
    fwrite(STDERR, "bind fail\n");
    exit(1);
}
echo 'listen=', (int) socket_listen($server, 1), "\n";

$client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (!socket_connect($client, '127.0.0.1', $port)) {
    fwrite(STDERR, "connect fail\n");
    exit(1);
}
$peer = socket_accept($server);
echo 'accept=', $peer instanceof Socket ? 'Socket' : gettype($peer), "\n";

socket_write($client, 'ping');
echo 'got=', socket_read($peer, 16), "\n";

socket_close($peer);
socket_close($client);
socket_close($server);
echo "done\n";
--EXPECT--
exists=1
setopt=1
getopt=1
listen=1
accept=Socket
got=ping
done
