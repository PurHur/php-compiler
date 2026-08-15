<?php

declare(strict_types=1);

/**
 * Repro #31332 — thin AOT must lower socket_recvfrom().
 * AF_UNIX create_pair is the reliable AOT socket source (#27423 / #31308).
 * php-src: ext/sockets/sockets.c PHP_FUNCTION(socket_recvfrom)
 */
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    fwrite(STDERR, "pair_fail\n");
    exit(1);
}
@socket_set_nonblock($pair[0]);
$data = '';
$addr = '';
$port = 0;
$n = @socket_recvfrom($pair[0], $data, 16, 0, $addr, $port);
echo 'recvfrom_linked=', (false === $n || is_int($n) ? '1' : '0'), "\n";
socket_close($pair[0]);
socket_close($pair[1]);
echo "recvfrom_aot_ok\n";
