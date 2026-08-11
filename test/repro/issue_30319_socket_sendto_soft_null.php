<?php

/**
 * Repro #30319 — socket_sendto soft-null $data / $address.
 * php-src: ext/sockets/sockets.c PHP_FUNCTION(socket_sendto)
 */
error_reporting(E_ALL);
$s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
try {
    var_export(socket_sendto($s, 'x', 1, 0, null, 53));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(socket_sendto($s, null, 0, 0, '127.0.0.1', 53));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
