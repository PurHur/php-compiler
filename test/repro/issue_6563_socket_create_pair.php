<?php

declare(strict_types=1);

/**
 * #6563 — socket_create_pair() AF_UNIX stream round-trip.
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_create_pair)
 */
var_export(function_exists('socket_create_pair'));
echo "\n";
$pair = [];
$ok = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
var_export($ok);
echo "\n";
if ($ok) {
    socket_write($pair[0], 'hi', 2);
    echo socket_read($pair[1], 2), "\n";
}
