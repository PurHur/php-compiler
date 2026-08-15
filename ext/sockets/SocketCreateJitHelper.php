<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * Owned-socket NestedJIT helpers for create/pair/write/read/sendto/name (#27394, #27423, #31308, #31293).
 *
 * One NestedJIT unit so {@see SocketsLibcThinAbi} FFI statics stay shared under thin AOT.
 * php-src: ext/sockets/sockets.c
 *
 * Note: socketpair(2) itself is invoked from LLVM (libc) in {@see \PHPCompiler\JIT\Builtin\SocketPairIoRuntime}
 * because NestedJIT cannot reliably read FFI out-params (#27423). This helper only registers
 * fds and performs write/read/sendto.
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

    /**
     * socket_bind() — same NestedJIT unit as registerOwnedArgv so pair/create fds resolve (#31241).
     *
     * @return int 1 on success, 0 on failure
     */
    public static function bindArgv(int $handle, string $addr, int $port): int
    {
        $domain = VmSocket::domainForLookupKey($handle);
        if (null === $domain) {
            $domain = ('' !== $addr && false === \strpos($addr, '/'))
                ? VmSockets::AF_INET
                : VmSockets::AF_UNIX;
        }
        $fd = self::fdForHandle($handle);
        if (null === $fd) {
            return 0;
        }
        if (VmSockets::AF_UNIX === $domain) {
            if (\strlen($addr) >= SocketsLibcThinAbi::UNIX_PATH_MAX) {
                throw new \ValueError(
                    'socket_bind(): Argument #2 ($address) must be less than '
                    .SocketsLibcThinAbi::UNIX_PATH_MAX
                );
            }
            $rc = SocketsLibcThinAbi::bindUnix($fd, $addr);
        } elseif (VmSockets::AF_INET === $domain) {
            $rc = SocketsLibcThinAbi::bindInet($fd, $addr, $port);
        } else {
            throw new \ValueError(
                'socket_bind(): Argument #1 ($socket) must be one of AF_UNIX, AF_INET, or AF_INET6'
            );
        }
        // NestedJIT FFI may leave bind(2) as float 0.0 — use == not === (#31241).
        if (0 == $rc) {
            VmSockets::clearErrorForLookupKey($handle);

            return 1;
        }
        $hostErr = SocketsLibcThinAbi::consumeHostLookupError();
        if (null !== $hostErr) {
            VmSockets::recordErrorForLookupKey($handle, $hostErr);

            return 0;
        }
        $errno = SocketsLibcThinAbi::readErrno();
        VmSockets::recordErrorForLookupKey($handle, $errno);

        return 0;
    }

    /**
     * socket_listen() — same NestedJIT unit as registerOwnedArgv (#31241).
     *
     * @return int 1 on success, 0 on failure
     */
    public static function listenArgv(int $handle, int $backlog): int
    {
        $fd = self::fdForHandle($handle);
        if (null === $fd) {
            return 0;
        }
        $rc = SocketsLibcThinAbi::listen($fd, $backlog);
        // NestedJIT FFI may leave listen(2) as float 0.0 — use == not === (#31241).
        if (0 == $rc) {
            VmSockets::clearErrorForLookupKey($handle);

            return 1;
        }
        $errno = SocketsLibcThinAbi::readErrno();
        VmSockets::recordErrorForLookupKey($handle, $errno);

        return 0;
    }

    /**
     * socket_shutdown() — same NestedJIT unit as registerOwnedArgv so pair fds resolve (#31292).
     *
     * @return int 1 on success, 0 on failure
     */
    public static function shutdownArgv(int $handle, int $how): int
    {
        $fd = self::fdForHandle($handle);
        if (null === $fd) {
            return 0;
        }
        $rc = SocketsLibcThinAbi::shutdown($fd, $how);
        // NestedJIT FFI may leave shutdown(2) as float 0.0 — use == (#31241/#31292).
        if (0 == $rc) {
            VmSockets::clearErrorForLookupKey($handle);

            return 1;
        }
        $errno = SocketsLibcThinAbi::readErrno();
        VmSockets::recordErrorForLookupKey($handle, $errno);

        return 0;
    }

    /**
     * socket_send() — send(2)/write(2) with flags (#31294).
     *
     * @return int bytes written, or -1 on failure
     */
    public static function sendArgv(int $handle, string $data, int $length, int $flags): int
    {
        $fd = self::fdForHandle($handle);
        if (null === $fd) {
            return -1;
        }
        if ($length < 0) {
            throw new \ValueError('socket_send(): Argument #3 ($length) must be greater than or equal to 0');
        }
        $data = (string) $data;
        if ($length > \strlen($data)) {
            $length = \strlen($data);
        }
        $n = SocketsLibcThinAbi::send($fd, $data, $length, $flags);
        $n = (int) $n;
        if ($n < 0) {
            $errno = SocketsLibcThinAbi::readErrno();
            VmSockets::recordErrorForLookupKey($handle, $errno);

            return -1;
        }
        VmSockets::clearErrorForLookupKey($handle);

        return $n;
    }

    /**
     * socket_recv() — recv(2)/read(2); stash buffer for by-ref LLVM write (#31294).
     *
     * @return int bytes (>=0), -1 on error, -2 when length < 1 (false, no data touch)
     */
    public static function recvArgv(int $handle, int $length, int $flags): int
    {
        self::$lastRecvData = '';
        self::$lastRecvEof = false;
        if ($length < 1) {
            return -2;
        }
        $fd = self::fdForHandle($handle);
        if (null === $fd) {
            return -1;
        }
        $data = SocketsLibcThinAbi::recv($fd, $length, $flags);
        // NestedJIT FFI may yield null instead of false under thin AOT (#31294).
        if (false === $data || null === $data) {
            $errno = SocketsLibcThinAbi::readErrno();
            VmSockets::recordErrorForLookupKey($handle, $errno);

            return -1;
        }
        VmSockets::clearErrorForLookupKey($handle);
        $data = (string) $data;
        if ('' === $data) {
            self::$lastRecvEof = true;

            return 0;
        }
        self::$lastRecvData = $data;

        return \strlen($data);
    }

    public static function recvDataArgv(): string
    {
        return self::$lastRecvData;
    }

    /** 1 when last recv was EOF (null &$data, return 0). */
    public static function recvEofArgv(): int
    {
        return self::$lastRecvEof ? 1 : 0;
    }

    /**
     * socket_getsockname() — stash AF_INET name; LLVM reads via nameAddr/namePort (#31293).
     *
     * @return int 1 on success, 0 on failure
     */
    public static function getsocknameOkArgv(int $handle): int
    {
        return self::nameOkArgv($handle, 'getsockname');
    }

    /**
     * socket_getpeername() — stash AF_INET peer; LLVM reads via nameAddr/namePort (#31293).
     *
     * @return int 1 on success, 0 on failure
     */
    public static function getpeernameOkArgv(int $handle): int
    {
        return self::nameOkArgv($handle, 'getpeername');
    }

    /** Last successful {@see getsocknameOkArgv}/{@see getpeernameOkArgv} address. */
    public static function nameAddrArgv(): string
    {
        return self::$lastNameAddr;
    }

    /** Last successful {@see getsocknameOkArgv}/{@see getpeernameOkArgv} port. */
    public static function namePortArgv(): int
    {
        return self::$lastNamePort;
    }

    /**
     * @param 'getsockname'|'getpeername' $which
     */
    private static function nameOkArgv(int $handle, string $which): int
    {
        self::$lastNameAddr = '';
        self::$lastNamePort = 0;
        $fd = self::fdForHandle($handle);
        if (null === $fd) {
            return 0;
        }
        $name = 'getpeername' === $which
            ? SocketsLibcThinAbi::getpeernameInet($fd)
            : SocketsLibcThinAbi::getsocknameInet($fd);
        if (false === $name) {
            $errno = SocketsLibcThinAbi::readErrno();
            VmSockets::recordErrorForLookupKey($handle, $errno);

            return 0;
        }
        VmSockets::clearErrorForLookupKey($handle);
        self::$lastNameAddr = $name[0];
        self::$lastNamePort = (int) $name[1];

        return 1;
    }

    /**
     * socket_sendto() — AF_INET sendto(2); same NestedJIT unit so owned fds resolve (#31308).
     *
     * @return int bytes written, or -1 on failure
     */
    public static function sendtoArgv(
        int $handle,
        string $data,
        int $length,
        int $flags,
        string $addr,
        int $port
    ): int {
        $fd = self::fdForHandle($handle);
        if (null === $fd) {
            return -1;
        }
        if ($length < 0) {
            $length = 0;
        }
        if ($length > \strlen($data)) {
            $length = \strlen($data);
        }
        $n = SocketsLibcThinAbi::sendtoInet($fd, $data, $length, $flags, $addr, $port);
        // NestedJIT FFI may leave sendto as float — normalize (#31241/#31308).
        $n = (int) $n;
        if ($n < 0) {
            $hostErr = SocketsLibcThinAbi::consumeHostLookupError();
            if (null !== $hostErr) {
                VmSockets::recordErrorForLookupKey($handle, $hostErr);

                return -1;
            }
            $errno = SocketsLibcThinAbi::readErrno();
            VmSockets::recordErrorForLookupKey($handle, $errno);

            return -1;
        }
        VmSockets::clearErrorForLookupKey($handle);

        return $n;
    }

    /**
     * socket_accept() — returns client fd, or -1 on failure (#31242).
     * Caller allocates Socket + {@see registerOwnedArgv} (mirror create).
     */
    public static function acceptArgv(int $handle): int
    {
        $fd = self::fdForHandle($handle);
        if (null === $fd) {
            return -1;
        }
        $client = SocketsLibcThinAbi::accept($fd);
        // NestedJIT FFI may leave accept(2) as float — normalize (#31241/#31242).
        $client = (int) $client;
        if ($client < 0) {
            $errno = SocketsLibcThinAbi::readErrno();
            VmSockets::recordErrorForLookupKey($handle, $errno);

            return -1;
        }

        return $client;
    }

    /** AF_* for NestedJIT handle, or AF_INET if unknown (#31242). */
    public static function domainForHandleArgv(int $handle): int
    {
        $domain = VmSocket::domainForLookupKey($handle);

        return null === $domain ? VmSockets::AF_INET : $domain;
    }

    /**
     * socket_create_listen() — AF_INET listener fd, or -1 (#31242).
     * Caller allocates Socket + registerOwnedArgv(AF_INET).
     */
    public static function createListenFdArgv(int $port, int $backlog): int
    {
        $port = $port & 0xffff;
        if (!SocketsLibcThinAbi::available()) {
            return -1;
        }
        $fd = SocketsLibcThinAbi::socket(VmSockets::AF_INET, SocketConstants::SOCK_STREAM, 0);
        if ($fd < 0) {
            VmSockets::recordLibcErrno(null);

            return -1;
        }
        // NestedJIT FFI may leave bind/listen as float 0.0 — use == (#31241).
        if (0 != SocketsLibcThinAbi::bindInet($fd, '0.0.0.0', $port)) {
            VmSockets::recordLibcErrno(null);
            SocketsLibcThinAbi::close($fd);

            return -1;
        }
        if (0 != SocketsLibcThinAbi::listen($fd, $backlog)) {
            VmSockets::recordLibcErrno(null);
            SocketsLibcThinAbi::close($fd);

            return -1;
        }

        return $fd;
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

    private static string $lastRecvData = '';

    private static bool $lastRecvEof = false;

    private static string $lastNameAddr = '';

    private static int $lastNamePort = 0;

    private static int $pairFdByHandle0 = 0;

    private static int $pairFdByHandle1 = 0;

    private static int $pairFd0 = -1;

    private static int $pairFd1 = -1;
}
