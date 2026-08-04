<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * Owned-socket NestedJIT helpers for create/pair/write/read (#27394, #27423).
 *
 * One NestedJIT unit so {@see SocketsLibcThinAbi} FFI statics stay shared under thin AOT.
 * php-src: ext/sockets/sockets.c
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

    /**
     * socketpair(2) + register two JIT Socket object addresses (#27423).
     *
     * @return int 1 on success, 0 on failure
     */
    public static function createAndRegisterArgv(
        int $domain,
        int $type,
        int $protocol,
        int $objAddr0,
        int $objAddr1
    ): int {
        // Skip available() — NestedJIT bool return from available() is unreliable here;
        // socket()/socketpair() already null-check ffi() (#27423).
        $fds = SocketsLibcThinAbi::socketpair($domain, $type, $protocol);
        if (false === $fds) {
            VmSockets::recordLibcErrno(null);

            return 0;
        }
        if ($objAddr0 <= 0 || $objAddr1 <= 0) {
            SocketsLibcThinAbi::close($fds[0]);
            SocketsLibcThinAbi::close($fds[1]);

            return 0;
        }
        VmSocket::registerJitOwnedFd($objAddr0, $fds[0], $domain);
        VmSocket::registerJitOwnedFd($objAddr1, $fds[1], $domain);

        return 1;
    }

    /** @return int bytes written, or -1 on failure */
    public static function writeArgv(int $handle, string $data, int $length): int
    {
        $fd = VmSocket::fdForLookupKey($handle);
        if (null === $fd) {
            return -1;
        }
        if ($length < 0) {
            throw new \ValueError('socket_write(): Argument #3 ($length) must be greater than or equal to 0');
        }
        if ($length > \strlen($data)) {
            $length = \strlen($data);
        }
        $n = SocketsLibcThinAbi::send($fd, $data, $length, 0);

        return $n < 0 ? -1 : $n;
    }

    /**
     * Binary socket_read (PHP_BINARY_READ).
     *
     * Empty string means EOF or zero-length success; use {@see readFailedArgv} for errors.
     */
    public static function readArgv(int $handle, int $length): string
    {
        self::$readFailed = false;
        $fd = VmSocket::fdForLookupKey($handle);
        if (null === $fd) {
            self::$readFailed = true;

            return '';
        }
        if ($length < 1) {
            throw new \ValueError('socket_read(): Argument #2 ($length) must be greater than 0');
        }
        $data = SocketsLibcThinAbi::recv($fd, $length, 0);
        if (false === $data) {
            self::$readFailed = true;

            return '';
        }

        return $data;
    }

    public static function readFailedArgv(): int
    {
        return self::$readFailed ? 1 : 0;
    }

    private static bool $readFailed = false;
}
