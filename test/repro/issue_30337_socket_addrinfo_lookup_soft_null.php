<?php

/**
 * Repro #30337 — socket_addrinfo_lookup(null) soft-null: Deprecated + false.
 * php-src: ext/sockets/sockets.c PHP_FUNCTION(socket_addrinfo_lookup)
 */
error_reporting(E_ALL);
try {
    var_export(socket_addrinfo_lookup(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
