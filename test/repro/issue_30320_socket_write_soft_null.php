<?php

/**
 * Repro #30320 — socket_write(null) soft-null: Deprecated + unable to write + false.
 * php-src: ext/sockets/sockets.c PHP_FUNCTION(socket_write)
 */
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
try {
    var_export(socket_write($s, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
