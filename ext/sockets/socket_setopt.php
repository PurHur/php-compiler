<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * socket_setopt() — alias of socket_set_option() (php-src ext/sockets/sockets.c; #6533).
 */
final class socket_setopt extends socket_set_option
{
    public function __construct()
    {
        \PHPCompiler\Func\Internal::__construct('socket_setopt');
    }
}
