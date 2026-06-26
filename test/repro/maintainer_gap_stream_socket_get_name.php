<?php

declare(strict_types=1);

echo function_exists('stream_socket_get_name') ? '1' : '0', "\n";

$srv = stream_socket_server('tcp://127.0.0.1:0');
if (false === $srv) {
    fwrite(STDERR, "stream_socket_server failed\n");
    exit(1);
}
$name = stream_socket_get_name($srv, false);
echo $name !== false && str_starts_with($name, '127.0.0.1:') ? '1' : '0', "\n";
