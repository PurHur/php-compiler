<?php

declare(strict_types=1);

echo function_exists('stream_socket_accept') ? 'true' : 'false', "\n";

$srv = @stream_socket_server('tcp://127.0.0.1:0');
if (false === $srv) {
    echo "server-fail\n";
    exit(1);
}
$bound = stream_socket_get_name($srv, false);
if (!\is_string($bound) || '' === $bound) {
    echo "bind-fail\n";
    exit(1);
}
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
echo \is_resource($conn) ? 'true' : 'false', "\n";
echo '' !== $peer ? 'peer-ok' : 'peer-fail', "\n";
fclose($conn);
fclose($client);
fclose($srv);
