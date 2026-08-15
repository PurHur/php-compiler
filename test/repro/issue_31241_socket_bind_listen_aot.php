<?php

declare(strict_types=1);

/**
 * Repro #31241 — thin AOT must lower socket_bind()/socket_listen() (not LogicException stub).
 * Uses AF_UNIX create_pair (reliable under AOT; Zend also allows bind+listen on the pair).
 * php-src: ext/sockets/sockets.c PHP_FUNCTION(socket_bind) / PHP_FUNCTION(socket_listen)
 */
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    fwrite(STDERR, "pair_fail\n");
    exit(1);
}
@unlink('/tmp/phpc-31241-bind.sock');
$bound = @socket_bind($pair[0], '/tmp/phpc-31241-bind.sock');
var_export($bound);
echo "\n";
$listening = @socket_listen($pair[0], 1);
var_export($listening);
echo "\nbind_listen_ok\n";
socket_close($pair[0]);
socket_close($pair[1]);
@unlink('/tmp/phpc-31241-bind.sock');
