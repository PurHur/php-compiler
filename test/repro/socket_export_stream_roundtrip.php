<?php

declare(strict_types=1);

if (!function_exists('stream_socket_pair')) {
    fwrite(STDERR, "skip: stream_socket_pair unavailable\n");
    exit(0);
}

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if (false === $pair) {
    fwrite(STDERR, "skip: pair failed\n");
    exit(0);
}

[$a, $b] = $pair;
$socket = socket_import_stream($a);
if (false === $socket) {
    fwrite(STDERR, "fail: import\n");
    exit(1);
}

$stream = socket_export_stream($socket);
if (!is_resource($stream)) {
    fwrite(STDERR, "fail: export not resource\n");
    exit(1);
}

if ('stream' !== get_resource_type($stream)) {
    fwrite(STDERR, "fail: type ".get_resource_type($stream)."\n");
    exit(1);
}

fclose($a);
fclose($b);

echo "ok\n";
