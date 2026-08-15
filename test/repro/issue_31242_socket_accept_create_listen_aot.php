<?php

declare(strict_types=1);

/**
 * Repro #31242 — thin AOT must lower socket_create_listen()/socket_accept().
 * create_listen ephemeral; accept after bind/listen on create_pair (may be Socket|false).
 * php-src: ext/sockets/sockets.c PHP_FUNCTION(socket_create_listen) / PHP_FUNCTION(socket_accept)
 */
$server = @socket_create_listen(0);
var_export(is_object($server) || $server === false);
echo "\ncreate_listen_linked\n";
if (is_object($server)) {
    socket_close($server);
}

$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    fwrite(STDERR, "pair_fail\n");
    exit(1);
}
@unlink('/tmp/phpc-31242-accept.sock');
@socket_bind($pair[0], '/tmp/phpc-31242-accept.sock');
@socket_listen($pair[0], 1);
$client = @socket_accept($pair[0]);
var_export(is_object($client) || $client === false);
echo "\naccept_linked\n";
if (is_object($client)) {
    socket_close($client);
}
socket_close($pair[0]);
socket_close($pair[1]);
@unlink('/tmp/phpc-31242-accept.sock');
