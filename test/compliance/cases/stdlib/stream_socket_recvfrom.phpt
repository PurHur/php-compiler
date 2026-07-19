--TEST--
stdlib stream_socket_recvfrom() — TCP peer payload (issue #21007, ext/standard/streamsfuncs.c)
--FILE--
<?php
declare(strict_types=1);
echo function_exists('stream_socket_recvfrom') ? '1' : '0', "\n";
$srv = stream_socket_server('tcp://127.0.0.1:0');
if (false === $srv) {
    echo "server-fail\n";
    exit(1);
}
$addr = stream_socket_get_name($srv, false);
if (!is_string($addr) || !str_starts_with($addr, '127.0.0.1:')) {
    echo "name-fail\n";
    exit(1);
}
$cli = stream_socket_client('tcp://'.$addr);
if (false === $cli) {
    echo "client-fail\n";
    exit(1);
}
$peer = stream_socket_accept($srv);
if (false === $peer) {
    echo "accept-fail\n";
    exit(1);
}
fwrite($cli, 'hello');
$buf = stream_socket_recvfrom($peer, 100);
echo $buf === 'hello' ? '1' : '0', "\n";
try {
    stream_socket_recvfrom($peer, 0);
    echo "no-throw\n";
} catch (ValueError $e) {
    echo str_contains($e->getMessage(), 'must be greater than 0') ? '1' : '0', "\n";
}
fclose($cli);
fclose($peer);
fclose($srv);
--EXPECT--
1
1
1
