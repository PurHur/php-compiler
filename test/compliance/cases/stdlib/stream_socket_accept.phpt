--TEST--
stdlib stream_socket_accept() — TCP accept on server stream (#15346, ext/standard/streamsfuncs.c)
--FILE--
<?php
declare(strict_types=1);
echo function_exists('stream_socket_accept') ? '1' : '0', "\n";
$srv = @stream_socket_server('tcp://127.0.0.1:0');
if (false === $srv) {
    echo "server-fail\n";
    exit(1);
}
$bound = stream_socket_get_name($srv, false);
$client = @stream_socket_client('tcp://'.$bound, $errno, $errstr, 2);
if (false === $client) {
    echo "client-fail\n";
    exit(1);
}
$peer = '';
$conn = @stream_socket_accept($srv, 2.0, $peer);
if (false === $conn) {
    echo "accept-fail\n";
    exit(1);
}
echo is_resource($conn) ? '1' : '0', "\n";
echo $peer !== '' ? '1' : '0', "\n";
fclose($conn);
fclose($client);
fclose($srv);
--EXPECT--
1
1
1
