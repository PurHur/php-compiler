<?php
// Compile-only (#3437): stream_socket_pair() registration on AOT user-script path.
if (!function_exists('stream_socket_pair')) {
    exit(0);
}
$pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if (false === $pair) {
    exit(0);
}
[$a, $b] = $pair;
fclose($a);
fclose($b);
