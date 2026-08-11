<?php

/**
 * Repro #30315 — socket_bind(null) soft-null: Deprecated + Host lookup failed + false.
 * php-src: ext/sockets/sockets.c PHP_FUNCTION(socket_bind) / sockaddr_conv.c php_set_inet_addr
 */
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    var_export(socket_bind($s, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
