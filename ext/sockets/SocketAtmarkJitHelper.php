<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * socket_atmark() for compiled JIT/AOT modules (#9215, php-in-PHP).
 *
 * SSOT: {@see VmSockets::atmarkForFd} / {@see VmSocket::fdForLookupKey}.
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_atmark)
 */
final class SocketAtmarkJitHelper
{
    public static function atmarkForHandle(int $handle): bool
    {
        $fd = VmSocket::fdForLookupKey($handle);
        if (null === $fd) {
            return false;
        }

        return VmSockets::atmarkForFd($fd);
    }
}
