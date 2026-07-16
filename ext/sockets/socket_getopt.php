<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * socket_getopt() — alias of socket_get_option() (php-src ext/sockets/sockets.c; #6533).
 */
final class socket_getopt extends socket_get_option
{
    public function __construct()
    {
        \PHPCompiler\Func\Internal::__construct('socket_getopt');
    }
}
