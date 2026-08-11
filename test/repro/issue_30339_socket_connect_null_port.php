<?php

/**
 * Repro #30339 — socket_connect(..., null) port on AF_INET → ValueError.
 * php-src: ext/sockets/sockets.c PHP_FUNCTION(socket_connect)
 */
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    var_export(socket_connect($s, '127.0.0.1', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
