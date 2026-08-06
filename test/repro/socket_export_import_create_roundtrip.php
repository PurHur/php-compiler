<?php

declare(strict_types=1);

// Repro #28139 — socket_import_stream(socket_export_stream(socket_create(...))) must return Socket.

error_reporting(E_ALL);

$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (false === $s) {
    fwrite(STDERR, "fail: socket_create\n");
    exit(1);
}

$stream = socket_export_stream($s);
if (!is_resource($stream) || 'stream' !== get_resource_type($stream)) {
    fwrite(STDERR, "fail: export not stream\n");
    exit(1);
}

$meta = stream_get_meta_data($stream);
$type = $meta['stream_type'] ?? '';
$expectedTcp = extension_loaded('openssl') ? 'tcp_socket/ssl' : 'tcp_socket';
if ($expectedTcp !== $type) {
    fwrite(STDERR, "fail: stream_type {$type} expected {$expectedTcp}\n");
    exit(1);
}
if (isset($meta['wrapper_type'])) {
    fwrite(STDERR, 'fail: unexpected wrapper_type '.$meta['wrapper_type']."\n");
    exit(1);
}

$s2 = socket_import_stream($stream);
if (!$s2 instanceof Socket) {
    fwrite(STDERR, 'fail: import type '.get_debug_type($s2)."\n");
    exit(1);
}

socket_close($s);
echo "ok\n";
