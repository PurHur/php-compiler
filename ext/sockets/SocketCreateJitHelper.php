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
     * socket_set_option() int path (SO_REUSEADDR, …) — NestedJIT (#31295).
     * Timeval/linger array options stay VM-only (NestedJIT cannot take HT args).
     *
     * @return int 1 on success, 0 on failure
     */
    public static function setOptionIntArgv(int $handle, int $level, int $option, int $value): int
    {
        if (13 === $option || 20 === $option || 21 === $option) {
            // SO_LINGER / SO_RCVTIMEO / SO_SNDTIMEO require array — reject int path.
            throw new \TypeError(
                'socket_set_option(): Argument #4 ($value) must be of type array when option is SO_LINGER, SO_RCVTIMEO, or SO_SNDTIMEO'
            );
        }
        $fd = self::fdForHandle($handle);
        if (null === $fd) {
            return 0;
        }
        $rc = SocketsLibcThinAbi::setsockoptInt($fd, $level, $option, $value);
        if (0 == $rc) {
            VmSockets::clearErrorForLookupKey($handle);

            return 1;
        }
        $errno = SocketsLibcThinAbi::readErrno();
        VmSockets::recordErrorForLookupKey($handle, $errno);

        return 0;
    }

    /**
     * socket_get_option() int path — stash value; LLVM reads via getOptionValueArgv (#31295).
     *
     * @return int 1 on success, 0 on failure (linger/timeval → fail under NestedJIT)
     */
    public static function getOptionIntOkArgv(int $handle, int $level, int $option): int
    {
        self::$lastGetOptionValue = 0;
        if (13 === $option || 20 === $option || 21 === $option) {
            return 0;
        }
        $fd = self::fdForHandle($handle);
        if (null === $fd) {
            return 0;
        }
        $val = SocketsLibcThinAbi::getsockoptInt($fd, $level, $option);
        if (false === $val) {
            $errno = SocketsLibcThinAbi::readErrno();
            VmSockets::recordErrorForLookupKey($handle, $errno);

            return 0;
        }
        VmSockets::clearErrorForLookupKey($handle);
        self::$lastGetOptionValue = (int) $val;

        return 1;
    }

    public static function getOptionValueArgv(): int
    {
        return self::$lastGetOptionValue;
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
     * socket_recvfrom() — recvfrom(2) AF_INET; stash data/addr/port for by-ref LLVM (#31332).
     *
     * @return int bytes (>=0), -1 on error, -2 when length < 1 (false, no out-arg touch)
     */
    public static function recvfromArgv(int $handle, int $length, int $flags): int
    {
        self::$lastRecvfromData = '';
        self::$lastRecvfromAddr = '';
        self::$lastRecvfromPort = 0;
        if ($length < 1) {
            return -2;
        }
        $fd = self::fdForHandle($handle);
        if (null === $fd) {
            return -1;
        }
        $got = SocketsLibcThinAbi::recvfromInet($fd, $length, $flags);
        // NestedJIT FFI may yield null instead of false under thin AOT (#31294/#31332).
        if (false === $got || null === $got) {
            $errno = SocketsLibcThinAbi::readErrno();
            VmSockets::recordErrorForLookupKey($handle, $errno);

            return -1;
        }
        VmSockets::clearErrorForLookupKey($handle);
        self::$lastRecvfromData = (string) $got[0];
        self::$lastRecvfromAddr = (string) $got[1];
        self::$lastRecvfromPort = (int) $got[2];

        return \strlen(self::$lastRecvfromData);
    }

    public static function recvfromDataArgv(): string
    {
        return self::$lastRecvfromData;
    }

    public static function recvfromAddrArgv(): string
    {
        return self::$lastRecvfromAddr;
    }

    public static function recvfromPortArgv(): int
    {
        return self::$lastRecvfromPort;
    }

    // --- socket_select NestedJIT slots (#31355) — must live in this TU (NestedJIT
    // cannot lower cross-class static calls; they compile to null/0).
    private const MAX = 24;

    private static int $n = 0;

    private static int $readyN = 0;

    // Entry slots 0..23 — fd/events/kind/key/handle (unrolled accessors).
    private static int $e0fd = -1;
    private static int $e0ev = 0;
    private static int $e0kind = 0;
    private static int $e0key = 0;
    private static int $e0h = 0;
    private static int $e1fd = -1;
    private static int $e1ev = 0;
    private static int $e1kind = 0;
    private static int $e1key = 0;
    private static int $e1h = 0;
    private static int $e2fd = -1;
    private static int $e2ev = 0;
    private static int $e2kind = 0;
    private static int $e2key = 0;
    private static int $e2h = 0;
    private static int $e3fd = -1;
    private static int $e3ev = 0;
    private static int $e3kind = 0;
    private static int $e3key = 0;
    private static int $e3h = 0;
    private static int $e4fd = -1;
    private static int $e4ev = 0;
    private static int $e4kind = 0;
    private static int $e4key = 0;
    private static int $e4h = 0;
    private static int $e5fd = -1;
    private static int $e5ev = 0;
    private static int $e5kind = 0;
    private static int $e5key = 0;
    private static int $e5h = 0;
    private static int $e6fd = -1;
    private static int $e6ev = 0;
    private static int $e6kind = 0;
    private static int $e6key = 0;
    private static int $e6h = 0;
    private static int $e7fd = -1;
    private static int $e7ev = 0;
    private static int $e7kind = 0;
    private static int $e7key = 0;
    private static int $e7h = 0;

    private static int $r0kind = 0;
    private static int $r0key = 0;
    private static int $r0h = 0;
    private static int $r1kind = 0;
    private static int $r1key = 0;
    private static int $r1h = 0;
    private static int $r2kind = 0;
    private static int $r2key = 0;
    private static int $r2h = 0;
    private static int $r3kind = 0;
    private static int $r3key = 0;
    private static int $r3h = 0;
    private static int $r4kind = 0;
    private static int $r4key = 0;
    private static int $r4h = 0;
    private static int $r5kind = 0;
    private static int $r5key = 0;
    private static int $r5h = 0;
    private static int $r6kind = 0;
    private static int $r6key = 0;
    private static int $r6h = 0;
    private static int $r7kind = 0;
    private static int $r7key = 0;
    private static int $r7h = 0;

    public static function selectResetArgv(): int
    {
        self::$n = 0;
        self::$readyN = 0;

        return 0;
    }

    /**
     * Register one Socket for poll with an already-resolved fd (from SocketCreateJitHelper::fdForHandle).
     * kind: 1=read, 2=write, 3=except.
     *
     * @return int 0 ok, -2 overflow
     */
    private static function selectAddWithFdArgv(int $handle, int $fd, int $kind, int $key): int
    {
        if (self::$n >= 8) {
            return -2;
        }
        $polLin = 0x001;
        $polLout = 0x004;
        $polLerr = 0x008;
        $polLhup = 0x010;
        $polLpri = 0x002;
        $events = $polLin | $polLhup;
        if (2 === $kind) {
            $events = $polLout;
        } elseif (3 === $kind) {
            $events = $polLerr | $polLhup | $polLpri;
        }
        $i = self::$n;
        self::$n = $i + 1;
        self::storeEntry($i, $fd, $events, $kind, $key, $handle);

        return 0;
    }

    /** @return int 0 ok, -1 missing fd, -2 overflow */
    public static function selectAddArgv(int $handle, int $kind, int $key): int
    {
        $fd = self::fdForHandle($handle);
        if (null === $fd) {
            return -1;
        }

        return self::selectAddWithFdArgv($handle, $fd, $kind, $key);
    }

    public static function selectRunArgv(int $seconds, int $microseconds): int
    {
        self::$readyN = 0;
        $timeoutMs = -1;
        if ($seconds >= 0) {
            $timeoutMs = ($seconds * 1000) + (int) \floor($microseconds / 1000);
            if ($timeoutMs < 0) {
                $timeoutMs = 0;
            }
        }
        self::$timeoutMs = $timeoutMs;
        $n = self::$n;
        if (0 === $n) {
            if ($timeoutMs > 0) {
                usleep($timeoutMs * 1000);
            }

            return 0;
        }

        // Poll itself runs in LLVM libc (NestedJIT cannot read FFI revents — peer #27423).
        return $n;
    }

    public static function selectTimeoutMsArgv(): int
    {
        return self::$timeoutMs;
    }

    public static function selectEntryCountArgv(): int
    {
        return self::$n;
    }

    public static function selectEntryFdArgv(int $i): int
    {
        return self::entryFd($i);
    }

    public static function selectEntryEvArgv(int $i): int
    {
        return self::entryEv($i);
    }

    /** Record ready slot after LLVM poll sees revents. Fully unrolled — NestedJIT aborts on storeReady/entry* helpers (#31355). */
    public static function selectMarkReadyArgv(int $i): int
    {
        if ($i < 0) {
            return self::$readyN;
        }
        if ($i >= self::$n) {
            return self::$readyN;
        }
        $kind = 0;
        $key = 0;
        $h = 0;
        if (0 === $i) {
            $kind = self::$e0kind;
            $key = self::$e0key;
            $h = self::$e0h;
        } elseif (1 === $i) {
            $kind = self::$e1kind;
            $key = self::$e1key;
            $h = self::$e1h;
        } elseif (2 === $i) {
            $kind = self::$e2kind;
            $key = self::$e2key;
            $h = self::$e2h;
        } elseif (3 === $i) {
            $kind = self::$e3kind;
            $key = self::$e3key;
            $h = self::$e3h;
        } elseif (4 === $i) {
            $kind = self::$e4kind;
            $key = self::$e4key;
            $h = self::$e4h;
        } elseif (5 === $i) {
            $kind = self::$e5kind;
            $key = self::$e5key;
            $h = self::$e5h;
        } elseif (6 === $i) {
            $kind = self::$e6kind;
            $key = self::$e6key;
            $h = self::$e6h;
        } else {
            $kind = self::$e7kind;
            $key = self::$e7key;
            $h = self::$e7h;
        }
        $r = self::$readyN;
        if (0 === $r) {
            self::$r0kind = $kind;
            self::$r0key = $key;
            self::$r0h = $h;
        } elseif (1 === $r) {
            self::$r1kind = $kind;
            self::$r1key = $key;
            self::$r1h = $h;
        } elseif (2 === $r) {
            self::$r2kind = $kind;
            self::$r2key = $key;
            self::$r2h = $h;
        } elseif (3 === $r) {
            self::$r3kind = $kind;
            self::$r3key = $key;
            self::$r3h = $h;
        } elseif (4 === $r) {
            self::$r4kind = $kind;
            self::$r4key = $key;
            self::$r4h = $h;
        } elseif (5 === $r) {
            self::$r5kind = $kind;
            self::$r5key = $key;
            self::$r5h = $h;
        } elseif (6 === $r) {
            self::$r6kind = $kind;
            self::$r6key = $key;
            self::$r6h = $h;
        } else {
            self::$r7kind = $kind;
            self::$r7key = $key;
            self::$r7h = $h;
        }
        self::$readyN = $r + 1;

        return self::$readyN;
    }

    private static int $timeoutMs = 0;

    public static function selectReadyCountArgv(): int
    {
        return self::$readyN;
    }

    public static function selectReadyHandleArgv(int $i): int
    {
        return self::readyH($i);
    }

    public static function selectReadyKindArgv(int $i): int
    {
        return self::readyKind($i);
    }

    public static function selectReadyKeyArgv(int $i): int
    {
        return self::readyKey($i);
    }

    private static function storeEntry(int $i, int $fd, int $ev, int $kind, int $key, int $h): void
    {
        if (0 === $i) {
            self::$e0fd = $fd;
            self::$e0ev = $ev;
            self::$e0kind = $kind;
            self::$e0key = $key;
            self::$e0h = $h;
        } elseif (1 === $i) {
            self::$e1fd = $fd;
            self::$e1ev = $ev;
            self::$e1kind = $kind;
            self::$e1key = $key;
            self::$e1h = $h;
        } elseif (2 === $i) {
            self::$e2fd = $fd;
            self::$e2ev = $ev;
            self::$e2kind = $kind;
            self::$e2key = $key;
            self::$e2h = $h;
        } elseif (3 === $i) {
            self::$e3fd = $fd;
            self::$e3ev = $ev;
            self::$e3kind = $kind;
            self::$e3key = $key;
            self::$e3h = $h;
        } elseif (4 === $i) {
            self::$e4fd = $fd;
            self::$e4ev = $ev;
            self::$e4kind = $kind;
            self::$e4key = $key;
            self::$e4h = $h;
        } elseif (5 === $i) {
            self::$e5fd = $fd;
            self::$e5ev = $ev;
            self::$e5kind = $kind;
            self::$e5key = $key;
            self::$e5h = $h;
        } elseif (6 === $i) {
            self::$e6fd = $fd;
            self::$e6ev = $ev;
            self::$e6kind = $kind;
            self::$e6key = $key;
            self::$e6h = $h;
        } else {
            self::$e7fd = $fd;
            self::$e7ev = $ev;
            self::$e7kind = $kind;
            self::$e7key = $key;
            self::$e7h = $h;
        }
    }

    private static function entryFd(int $i): int
    {
        if (0 === $i) {
            return self::$e0fd;
        }
        if (1 === $i) {
            return self::$e1fd;
        }
        if (2 === $i) {
            return self::$e2fd;
        }
        if (3 === $i) {
            return self::$e3fd;
        }
        if (4 === $i) {
            return self::$e4fd;
        }
        if (5 === $i) {
            return self::$e5fd;
        }
        if (6 === $i) {
            return self::$e6fd;
        }

        return self::$e7fd;
    }

    private static function entryEv(int $i): int
    {
        if (0 === $i) {
            return self::$e0ev;
        }
        if (1 === $i) {
            return self::$e1ev;
        }
        if (2 === $i) {
            return self::$e2ev;
        }
        if (3 === $i) {
            return self::$e3ev;
        }
        if (4 === $i) {
            return self::$e4ev;
        }
        if (5 === $i) {
            return self::$e5ev;
        }
        if (6 === $i) {
            return self::$e6ev;
        }

        return self::$e7ev;
    }

    private static function entryKind(int $i): int
    {
        if (0 === $i) {
            return self::$e0kind;
        }
        if (1 === $i) {
            return self::$e1kind;
        }
        if (2 === $i) {
            return self::$e2kind;
        }
        if (3 === $i) {
            return self::$e3kind;
        }
        if (4 === $i) {
            return self::$e4kind;
        }
        if (5 === $i) {
            return self::$e5kind;
        }
        if (6 === $i) {
            return self::$e6kind;
        }

        return self::$e7kind;
    }

    private static function entryKey(int $i): int
    {
        if (0 === $i) {
            return self::$e0key;
        }
        if (1 === $i) {
            return self::$e1key;
        }
        if (2 === $i) {
            return self::$e2key;
        }
        if (3 === $i) {
            return self::$e3key;
        }
        if (4 === $i) {
            return self::$e4key;
        }
        if (5 === $i) {
            return self::$e5key;
        }
        if (6 === $i) {
            return self::$e6key;
        }

        return self::$e7key;
    }

    private static function entryH(int $i): int
    {
        if (0 === $i) {
            return self::$e0h;
        }
        if (1 === $i) {
            return self::$e1h;
        }
        if (2 === $i) {
            return self::$e2h;
        }
        if (3 === $i) {
            return self::$e3h;
        }
        if (4 === $i) {
            return self::$e4h;
        }
        if (5 === $i) {
            return self::$e5h;
        }
        if (6 === $i) {
            return self::$e6h;
        }

        return self::$e7h;
    }

    private static function storeReady(int $i, int $kind, int $key, int $h): void
    {
        if (0 === $i) {
            self::$r0kind = $kind;
            self::$r0key = $key;
            self::$r0h = $h;
        } elseif (1 === $i) {
            self::$r1kind = $kind;
            self::$r1key = $key;
            self::$r1h = $h;
        } elseif (2 === $i) {
            self::$r2kind = $kind;
            self::$r2key = $key;
            self::$r2h = $h;
        } elseif (3 === $i) {
            self::$r3kind = $kind;
            self::$r3key = $key;
            self::$r3h = $h;
        } elseif (4 === $i) {
            self::$r4kind = $kind;
            self::$r4key = $key;
            self::$r4h = $h;
        } elseif (5 === $i) {
            self::$r5kind = $kind;
            self::$r5key = $key;
            self::$r5h = $h;
        } elseif (6 === $i) {
            self::$r6kind = $kind;
            self::$r6key = $key;
            self::$r6h = $h;
        } else {
            self::$r7kind = $kind;
            self::$r7key = $key;
            self::$r7h = $h;
        }
    }

    private static function readyKind(int $i): int
    {
        if (0 === $i) {
            return self::$r0kind;
        }
        if (1 === $i) {
            return self::$r1kind;
        }
        if (2 === $i) {
            return self::$r2kind;
        }
        if (3 === $i) {
            return self::$r3kind;
        }
        if (4 === $i) {
            return self::$r4kind;
        }
        if (5 === $i) {
            return self::$r5kind;
        }
        if (6 === $i) {
            return self::$r6kind;
        }

        return self::$r7kind;
    }

    private static function readyKey(int $i): int
    {
        if (0 === $i) {
            return self::$r0key;
        }
        if (1 === $i) {
            return self::$r1key;
        }
        if (2 === $i) {
            return self::$r2key;
        }
        if (3 === $i) {
            return self::$r3key;
        }
        if (4 === $i) {
            return self::$r4key;
        }
        if (5 === $i) {
            return self::$r5key;
        }
        if (6 === $i) {
            return self::$r6key;
        }

        return self::$r7key;
    }

    private static function readyH(int $i): int
    {
        if (0 === $i) {
            return self::$r0h;
        }
        if (1 === $i) {
            return self::$r1h;
        }
        if (2 === $i) {
            return self::$r2h;
        }
        if (3 === $i) {
            return self::$r3h;
        }
        if (4 === $i) {
            return self::$r4h;
        }
        if (5 === $i) {
            return self::$r5h;
        }
        if (6 === $i) {
            return self::$r6h;
        }

        return self::$r7h;
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

    private static int $lastGetOptionValue = 0;

    private static string $lastNameAddr = '';

    private static int $lastNamePort = 0;

    private static string $lastRecvfromData = '';

    private static string $lastRecvfromAddr = '';

    private static int $lastRecvfromPort = 0;

    private static int $pairFdByHandle0 = 0;

    private static int $pairFdByHandle1 = 0;

    private static int $pairFd0 = -1;

    private static int $pairFd1 = -1;
}
