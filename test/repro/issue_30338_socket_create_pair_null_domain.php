<?php

/**
 * Repro #30338 — socket_create_pair(null) soft-null: Deprecated + ValueError domain.
 * php-src: ext/sockets/sockets.c PHP_FUNCTION(socket_create_pair)
 */
error_reporting(E_ALL);
$fds = null;
try {
    var_export(socket_create_pair(null, SOCK_STREAM, 0, $fds));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
