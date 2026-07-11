<?php

declare(strict_types=1);

if (!function_exists('stream_socket_enable_crypto')) {
    fwrite(STDERR, "MISSING: stream_socket_enable_crypto\n");
    exit(1);
}

echo "ok\n";
