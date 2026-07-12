--TEST--
socket_export_stream() round-trip after socket_import_stream() (#6349, ext/sockets/sockets.c)
--SKIPIF--
<?php if (!function_exists('socket_export_stream')) die('skip socket_export_stream'); ?>
--FILE--
<?php
declare(strict_types=1);

if (!function_exists('stream_socket_pair')) {
    echo "pair_skip\n";
    exit(0);
}

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if (false === $pair) {
    echo "pair_fail\n";
    exit(0);
}

[$a, $b] = $pair;
$socket = socket_import_stream($a);
$stream = socket_export_stream($socket);

echo is_resource($stream) ? '1' : '0', "\n";
echo get_resource_type($stream), "\n";

fclose($a);
fclose($b);
--EXPECT--
1
stream
