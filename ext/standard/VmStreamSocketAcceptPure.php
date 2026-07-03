<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\sockets\VmSockets;

/**
 * stream_socket_accept() — host accept on adopted server streams (#15346).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_accept)
 */
final class VmStreamSocketAcceptPure
{
    public static function available(): bool
    {
        return \function_exists('stream_socket_accept');
    }

    /**
     * @return array{0: int|false, 1: string} accepted handle and peer address
     */
    public static function accept(int $serverHandle, ?float $timeout = null): array
    {
        if (!self::available()) {
            return [false, ''];
        }

        $serverResource = VmFs::lookupResource($serverHandle);
        if (!\is_resource($serverResource)) {
            return [false, ''];
        }

        $peername = '';
        $beforeSockets = VmSockets::enumerateSocketFds();
        if (null === $timeout) {
            $conn = @\stream_socket_accept($serverResource, null, $peername);
        } else {
            $conn = @\stream_socket_accept($serverResource, $timeout, $peername);
        }
        if (false === $conn) {
            return [false, ''];
        }

        $socketFd = self::discoverNewSocketFd($beforeSockets);
        $handle = VmFs::adoptStreamResource($conn, $peername, $socketFd);
        if (false === $handle) {
            @\fclose($conn);

            return [false, ''];
        }

        return [$handle, $peername];
    }

    /**
     * @param array<int, string> $beforeSockets
     */
    private static function discoverNewSocketFd(array $beforeSockets): ?int
    {
        $after = VmSockets::enumerateSocketFds();
        foreach ($after as $fd => $target) {
            if (!isset($beforeSockets[$fd])) {
                return $fd;
            }
        }

        return null;
    }
}
