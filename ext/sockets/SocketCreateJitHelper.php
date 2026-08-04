<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * socket_create() for compiled JIT/AOT modules (#27394, php-in-PHP).
 *
 * SSOT: {@see VmSockets::create} / {@see VmSocket::registerJitOwnedFd}.
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_create)
 */
final class SocketCreateJitHelper
{
    /** LLVM i64 ABI — new socket fd, or -1 on failure (domain already validated in LLVM). */
    public static function createFdArgv(int $domain, int $type, int $protocol): int
    {
        if (!\in_array($domain, [VmSockets::AF_UNIX, VmSockets::AF_INET, VmSockets::AF_INET6], true)) {
            throw new \ValueError(
                'socket_create(): Argument #1 ($domain) must be one of AF_UNIX, AF_INET6, or AF_INET'
            );
        }
        if (!SocketsLibcThinAbi::available()) {
            return -1;
        }
        $fd = SocketsLibcThinAbi::socket($domain, $type, $protocol);
        if ($fd < 0) {
            VmSockets::recordLibcErrno(null);

            return -1;
        }

        return $fd;
    }

    public static function registerOwnedArgv(int $objAddr, int $fd, int $domain): void
    {
        VmSocket::registerJitOwnedFd($objAddr, $fd, $domain);
    }
}
