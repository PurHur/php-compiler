<?php

/**
 * Repro #30321 — socket_send(null) soft-null: Deprecated + Unable to write + false.
 * php-src: ext/sockets/sockets.c PHP_FUNCTION(socket_send)
 */
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    var_export(socket_send($s, null, 0, 0));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
