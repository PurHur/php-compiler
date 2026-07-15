--TEST--
stdlib socket_create/connect/read/write loopback round-trip (#19286, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);

echo 'exists=', (int) function_exists('socket_create'), "\n";
echo 'loaded=', (int) extension_loaded('sockets'), "\n";

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (false === $server) {
    fwrite(STDERR, "server fail: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
if (!preg_match('/:(\d+)$/', $name, $m)) {
    fwrite(STDERR, "bad name: $name\n");
    exit(1);
}
$port = (int) $m[1];

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
echo 'class=', $sock instanceof Socket ? 'Socket' : gettype($sock), "\n";
$ok = socket_connect($sock, '127.0.0.1', $port);
echo 'connect=', (int) $ok, "\n";

$peer = stream_socket_accept($server, 2.0);
if (false === $peer) {
    fwrite(STDERR, "accept fail\n");
    exit(1);
}

$n = socket_write($sock, 'ping');
echo 'wrote=', $n, "\n";
$got = fread($peer, 16);
echo 'stream_got=', $got, "\n";

fwrite($peer, "pong\n");
$back = socket_read($sock, 16, PHP_NORMAL_READ);
echo 'sock_got=', var_export($back, true), "\n";

socket_close($sock);
fclose($peer);
fclose($server);
echo "done\n";
--EXPECT--
exists=1
loaded=1
class=Socket
connect=1
wrote=4
stream_got=ping
sock_got='pong
'
done
