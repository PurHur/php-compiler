<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\ErrorLastJitHelper;
use PHPCompiler\ext\standard\ErrorSilenceJitHelper;
use PHPCompiler\ext\standard\TriggerErrorJitHelper;
use PHPCompiler\VM\ErrorReporter;

/**
 * NestedJIT helpers for socket_connect() (#31240).
 *
 * Separate from {@see SocketCreateJitHelper} so createFdArgv NestedJIT/FFI stays stable
 * under thin AOT (bloating the create unit with ErrorLast/TriggerError imports broke
 * socket_create() → false).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_connect)
 */
final class SocketConnectJitHelper
{
    /** AF_* for NestedJIT handle, or -1 if unknown. */
    public static function domainForHandleArgv(int $handle): int
    {
        $domain = VmSocket::domainForLookupKey($handle);

        return null === $domain ? -1 : $domain;
    }

    /**
     * @param int $hasPort 1 when argc≥3 and port is non-null; 0 when omitted or null
     *
     * @return int 1 on success, 0 on failure, -2 when AF_INET/AF_INET6 port null/omitted
     */
    public static function connectArgv(int $handle, string $addr, int $port, int $hasPort): int
    {
        $domain = VmSocket::domainForLookupKey($handle);
        if (null === $domain) {
            // create registers domain in SocketCreateJitHelper NestedJIT; connect is a
            // separate unit and may not see that map — infer from address (#31240).
            $domain = ('' !== $addr && false === \strpos($addr, '/'))
                ? VmSockets::AF_INET
                : VmSockets::AF_UNIX;
        }
        if (0 === $hasPort && (VmSockets::AF_INET === $domain || VmSockets::AF_INET6 === $domain)) {
            // Catchable ValueError is emitted in LLVM from sentinel -2 (#31240).
            return -2;
        }
        $fd = VmSocket::fdForLookupKey($handle);
        if (null === $fd) {
            return 0;
        }
        if (VmSockets::AF_UNIX === $domain) {
            if (\strlen($addr) >= SocketsLibcThinAbi::UNIX_PATH_MAX) {
                throw new \ValueError(
                    'socket_connect(): Argument #2 ($address) must be less than '
                    .SocketsLibcThinAbi::UNIX_PATH_MAX
                );
            }
            $rc = SocketsLibcThinAbi::connectUnix($fd, $addr);
        } elseif (VmSockets::AF_INET === $domain) {
            $rc = SocketsLibcThinAbi::connectInet($fd, $addr, $port);
        } else {
            throw new \ValueError(
                'socket_connect(): Argument #1 ($socket) must be one of AF_UNIX, AF_INET, or AF_INET6'
            );
        }
        if (0 === $rc) {
            VmSockets::clearErrorForLookupKey($handle);

            return 1;
        }
        $hostErr = SocketsLibcThinAbi::consumeHostLookupError();
        if (null !== $hostErr) {
            VmSockets::recordErrorForLookupKey($handle, $hostErr);
            self::emitConnectWarning(
                'socket_connect(): Host lookup failed ['.$hostErr.']: '
                .SocketsLibcThinAbi::strerror($hostErr)
            );

            return 0;
        }
        $errno = SocketsLibcThinAbi::readErrno();
        VmSockets::recordErrorForLookupKey($handle, $errno);
        self::emitConnectWarning(
            'socket_connect(): unable to connect ['.$errno.']: '
            .SocketsLibcThinAbi::strerror($errno)
        );

        return 0;
    }

    private static function emitConnectWarning(string $message): void
    {
        ErrorLastJitHelper::record(ErrorReporter::E_WARNING, $message, '', 0);
        if (ErrorSilenceJitHelper::shouldDisplayCliError(ErrorReporter::E_WARNING)) {
            TriggerErrorJitHelper::stderrPrintCliError(
                ErrorReporter::E_WARNING,
                $message,
                '',
                0
            );
        }
    }
}
