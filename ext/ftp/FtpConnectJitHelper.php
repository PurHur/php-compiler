<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ext\sockets\SocketConstants;
use PHPCompiler\ext\sockets\SocketsLibcThinAbi;
use PHPCompiler\ext\sockets\VmSockets;
use PHPCompiler\ext\standard\TriggerErrorJitHelper;

/**
 * ftp_connect() for compiled JIT/AOT modules (#27393, php-in-PHP).
 *
 * Socket path only — never calls host {@code \ftp_connect} (would recurse under NestedJIT).
 * Hostname must be dotted IPv4 for {@see SocketsLibcThinAbi::connectInet} (issue repro uses
 * 127.0.0.1); DNS hostnames fail at connect() like a refused peer.
 * Greeting validation stays on the VM path ({@see VmFtpCore}); NestedJIT recv/string loops
 * currently orphan insert blocks under thin AOT.
 * php-src: ext/ftp/ftp.c — PHP_FUNCTION(ftp_connect)
 */
final class FtpConnectJitHelper
{
    private const SOL_SOCKET = 1;

    private const SO_RCVTIMEO = 20;

    private const SO_SNDTIMEO = 21;

    /**
     * LLVM i64 ABI — owned TCP fd after connect(2), or -1 on failure.
     */
    public static function connectFdArgv(string $hostname, int $port, int $timeout): int
    {
        if (!SocketsLibcThinAbi::available()) {
            self::warnConnectFailed(0, 'Connection refused');

            return -1;
        }

        $connectPort = -1 === $port ? 21 : $port;
        $fd = SocketsLibcThinAbi::socket(VmSockets::AF_INET, SocketConstants::SOCK_STREAM, 0);
        if ($fd < 0) {
            self::warnConnectFailed(SocketsLibcThinAbi::readErrno(), 'Connection refused');

            return -1;
        }

        $sec = $timeout > 0 ? $timeout : 90;
        SocketsLibcThinAbi::setsockoptTimeval($fd, self::SOL_SOCKET, self::SO_RCVTIMEO, $sec, 0);
        SocketsLibcThinAbi::setsockoptTimeval($fd, self::SOL_SOCKET, self::SO_SNDTIMEO, $sec, 0);

        $rc = SocketsLibcThinAbi::connectInet($fd, $hostname, $connectPort);
        if (0 !== $rc) {
            $errno = SocketsLibcThinAbi::readErrno();
            $err = SocketsLibcThinAbi::strerror($errno);
            SocketsLibcThinAbi::close($fd);
            self::warnConnectFailed($errno, '' !== $err ? $err : 'Connection refused');

            return -1;
        }

        return $fd;
    }

    public static function registerOwnedArgv(int $objAddr, int $fd, int $port, int $timeout): void
    {
        VmFtpCore::registerJitOwnedFd($objAddr, $fd, $port, $timeout);
    }

    private static function warnConnectFailed(int $errno, string $errstr): void
    {
        $detail = '' !== $errstr ? $errstr : 'Connection refused';
        if (0 !== $errno) {
            $detail .= ' ('.$errno.')';
        }
        TriggerErrorJitHelper::warning('ftp_connect(): connect() failed: '.$detail);
    }
}
