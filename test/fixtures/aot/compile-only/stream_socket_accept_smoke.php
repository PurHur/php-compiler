<?php
// Compile-only (#15346): stream_socket_accept() registration on AOT user-script path.
if (!function_exists('stream_socket_accept')) {
    exit(0);
}
$srv = @stream_socket_server('tcp://127.0.0.1:0');
if (false === $srv) {
    exit(0);
}
$bound = stream_socket_get_name($srv, false);
$client = @stream_socket_client('tcp://'.$bound, $errno, $errstr, 1);
$conn = @stream_socket_accept($srv, 1.0);
if (false !== $conn) {
    fclose($conn);
}
if (false !== $client) {
    fclose($client);
}
fclose($srv);
