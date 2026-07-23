<?php

declare(strict_types=1);

// Repro #22542 — socket_export_stream(socket_create(...)) must return a stream.

$s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if (false === $s) {
    fwrite(STDERR, "fail: socket_create\n");
    exit(1);
}

$st = socket_export_stream($s);
if (!is_resource($st)) {
    fwrite(STDERR, "fail: export not resource\n");
    exit(1);
}
if ('stream' !== get_resource_type($st)) {
    fwrite(STDERR, 'fail: type '.get_resource_type($st)."\n");
    exit(1);
}

$meta = stream_get_meta_data($st);
$type = $meta['stream_type'] ?? '';
if ('udp_socket' !== $type) {
    fwrite(STDERR, "fail: stream_type {$type}\n");
    exit(1);
}

$st2 = socket_export_stream($s);
if ($st !== $st2) {
    fwrite(STDERR, "fail: re-export not same resource\n");
    exit(1);
}

socket_close($s);
echo "ok\n";
