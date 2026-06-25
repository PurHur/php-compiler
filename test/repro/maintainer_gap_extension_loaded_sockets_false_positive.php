<?php

declare(strict_types=1);

/**
 * Maintainer repro: extension_loaded('sockets') must not be true without socket_create() (#11820).
 *
 * php-src: ext/sockets/sockets.c — module registration includes socket_create.
 */

if (!extension_loaded('sockets')) {
    if (!function_exists('socket_create')) {
        echo "ok\n";
        exit(0);
    }
    echo "fail: socket_create exists but extension_loaded('sockets') false\n";
    exit(1);
}

if (!function_exists('socket_create')) {
    echo "fail: extension_loaded('sockets') true but socket_create() missing\n";
    exit(1);
}

if (!in_array('sockets', get_loaded_extensions(), true)) {
    echo "fail: extension_loaded true but sockets not in get_loaded_extensions()\n";
    exit(1);
}

echo "ok\n";
