<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * socket_close() for compiled JIT/AOT modules (#27394, php-in-PHP).
 *
 * Owned-fd path only (socket_create) — avoids VmFs export fclose under NestedJIT thin AOT.
 * SSOT fd map: {@see VmSocket::ownedFdForLookupKey} / {@see VmSocket::releaseForLookupKey}.
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_close)
 */
final class SocketCloseJitHelper
{
    public static function closeForHandle(int $handle): void
    {
        if ($handle <= 0) {
            return;
        }
        $fd = VmSocket::ownedFdForLookupKey($handle);
        if (null !== $fd) {
            SocketsLibcThinAbi::close($fd);
        }
        VmSocket::releaseForLookupKey($handle);
    }
}
