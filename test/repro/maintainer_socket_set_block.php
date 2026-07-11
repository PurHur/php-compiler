<?php

declare(strict_types=1);

if (!function_exists('socket_set_nonblock')) {
    echo "socket_set_nonblock: no\n";
    echo "socket_set_block: no\n";
    exit(1);
}

echo "socket_set_nonblock: yes\n";
echo "socket_set_block: yes\n";

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if (false === $pair) {
    echo "pair: fail\n";
    exit(1);
}

$socket = socket_import_stream($pair[0]);
if (false === $socket) {
    echo "import: fail\n";
    exit(1);
}

$nonblock = socket_set_nonblock($socket);
$block = socket_set_block($socket);
echo 'nonblock=', var_export($nonblock, true), "\n";
echo 'block=', var_export($block, true), "\n";

if (true !== $nonblock || true !== $block) {
    exit(1);
}

echo "ok\n";
