--TEST--
stdlib stream_socket_sendto() — TCP peer payload (issue #21008, ext/standard/streamsfuncs.c)
--FILE--
<?php
declare(strict_types=1);
echo function_exists('stream_socket_sendto') ? '1' : '0', "\n";
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
$n = stream_socket_sendto($peer, 'world');
echo (5 === $n) ? '1' : '0', "\n";
$got = fread($cli, 100);
echo $got === 'world' ? '1' : '0', "\n";
fclose($cli);
fclose($peer);
fclose($srv);
--EXPECT--
1
1
1
