<?php

declare(strict_types=1);

/**
 * Repro #31240 — thin AOT must lower socket_connect() (not LogicException stub).
 * AF_UNIX create_pair is the reliable AOT socket source (#27423).
 * Null-port AF_INET ValueError: php bin/vm.php + SocketConnectNullPortVMTest (#30339).
 * php-src: ext/sockets/sockets.c PHP_FUNCTION(socket_connect)
 */
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    fwrite(STDERR, "pair_fail\n");
    exit(1);
}
$r = @socket_connect($pair[0], '/tmp/phpc-31240-connect.sock');
var_export(is_bool($r));
echo "\nconnect_linked_ok\n";
// VM-only null-port path (AF_INET) — still exercise when create works:
$s = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (is_object($s)) {
    try {
        socket_connect($s, '127.0.0.1', null);
        echo "null_unexpected\n";
    } catch (ValueError $e) {
        echo str_contains($e->getMessage(), 'cannot be null') ? "null_ok\n" : "null_bad\n";
    }
    socket_close($s);
} else {
    echo "null_skip_create_false\n";
}
socket_close($pair[0]);
socket_close($pair[1]);
