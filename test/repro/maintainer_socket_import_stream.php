<?php
declare(strict_types=1);

echo 'registered=', (int) function_exists('socket_import_stream'), "\n";

$stream = @fopen('php://memory', 'r+');
if (false === $stream) {
    fwrite(STDERR, "fopen failed\n");
    exit(1);
}
$bad = socket_import_stream($stream);
var_export($bad);
echo "\n";
fclose($stream);

if (!function_exists('stream_socket_pair')) {
    echo "pair_unavailable\n";
    exit(0);
}

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if (false === $pair) {
    echo "pair_failed\n";
    exit(0);
}
[$a, $b] = $pair;
$sock = socket_import_stream($a);
var_export($sock instanceof Socket);
echo "\n";
fclose($a);
fclose($b);
