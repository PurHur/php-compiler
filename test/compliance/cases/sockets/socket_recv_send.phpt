--TEST--
stdlib socket_recv/socket_send loopback round-trip (#20238, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);

echo 'recv=', (int) function_exists('socket_recv'), "\n";
echo 'send=', (int) function_exists('socket_send'), "\n";

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
$ok = socket_connect($sock, '127.0.0.1', $port);
echo 'connect=', (int) $ok, "\n";

$peer = stream_socket_accept($server, 2.0);
if (false === $peer) {
    fwrite(STDERR, "accept fail\n");
    exit(1);
}

$n = socket_send($sock, 'ping', 4, 0);
echo 'sent=', $n, "\n";
$got = fread($peer, 16);
echo 'stream_got=', $got, "\n";

fwrite($peer, 'pong');
$buf = 'stale';
$back = socket_recv($sock, $buf, 16, 0);
echo 'recv=', $back, ' buf=', var_export($buf, true), "\n";

socket_close($sock);
fclose($peer);
fclose($server);
echo "done\n";
--EXPECT--
recv=1
send=1
connect=1
sent=4
stream_got=ping
recv=4 buf='pong'
done
