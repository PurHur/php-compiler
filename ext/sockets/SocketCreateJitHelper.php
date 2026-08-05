<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * Owned-socket NestedJIT helpers for create/pair/write/read (#27394, #27423).
 *
 * One NestedJIT unit so {@see SocketsLibcThinAbi} FFI statics stay shared under thin AOT.
 * php-src: ext/sockets/sockets.c
 *
 * Note: socketpair(2) itself is invoked from LLVM (libc) in {@see \PHPCompiler\JIT\Builtin\SocketPairIoRuntime}
 * because NestedJIT cannot reliably read FFI out-params (#27423). This helper only registers
 * fds and performs write/read.
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
        // Helper-local map for pair write/read under NestedJIT (#27423).
        if ($objAddr <= 0 || $fd < 0) {
            return;
        }
        if (0 === self::$pairFdByHandle0) {
            self::$pairFdByHandle0 = $objAddr;
            self::$pairFd0 = $fd;

            return;
        }
        self::$pairFdByHandle1 = $objAddr;
        self::$pairFd1 = $fd;
    }

    /** Resolve Socket object handle to owned fd (-1 if missing). */
    public static function fdForHandleArgv(int $handle): int
    {
        $fd = self::fdForHandle($handle);

        return null === $fd ? -1 : $fd;
    }

    /** @return int bytes written, or -1 on failure */
    public static function writeArgv(int $handle, string $data, int $length): int
    {
        $fd = self::fdForHandle($handle);
        if (null === $fd) {
            return -1;
        }
        $payloadLen = \strlen($data);
        if ($payloadLen < 1) {
            return -1;
        }
        if ($length < 0) {
            throw new \ValueError('socket_write(): Argument #3 ($length) must be greater than or equal to 0');
        }
        if ($length > 0 && $length < $payloadLen) {
            $payloadLen = $length;
        }
        $n = SocketsLibcThinAbi::send($fd, $data, $payloadLen, 0);

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
        $fd = self::fdForHandle($handle);
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

    public static function markReadFailedArgv(): void
    {
        self::$readFailed = true;
    }

    public static function clearReadFailedArgv(): void
    {
        self::$readFailed = false;
    }

    public static function readFailedArgv(): int
    {
        return self::$readFailed ? 1 : 0;
    }

    private static function fdForHandle(int $handle): ?int
    {
        if ($handle > 0 && $handle === self::$pairFdByHandle0 && self::$pairFd0 >= 0) {
            return self::$pairFd0;
        }
        if ($handle > 0 && $handle === self::$pairFdByHandle1 && self::$pairFd1 >= 0) {
            return self::$pairFd1;
        }

        return VmSocket::fdForLookupKey($handle);
    }

    private static bool $readFailed = false;

    private static int $pairFdByHandle0 = 0;

    private static int $pairFdByHandle1 = 0;

    private static int $pairFd0 = -1;

    private static int $pairFd1 = -1;
}
