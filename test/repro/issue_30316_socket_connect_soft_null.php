<?php

/**
 * Repro #30316 — socket_connect(null) soft-null: Deprecated + Host lookup failed + false.
 * php-src: ext/sockets/sockets.c PHP_FUNCTION(socket_connect)
 */
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    var_export(socket_connect($s, null, 80));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
