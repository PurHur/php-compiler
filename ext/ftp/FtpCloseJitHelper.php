<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ext\sockets\SocketsLibcThinAbi;

/**
 * ftp_close() / ftp_quit() for compiled JIT/AOT modules (#31377, php-in-PHP).
 *
 * Owned-fd path only (ftp_connect NestedJIT) — peer {@see SocketCloseJitHelper} (#27394).
 * SSOT fd map: {@see VmFtpCore::jitOwnedFdForLookupKey} / {@see VmFtpCore::releaseJitOwnedForLookupKey}.
 * php-src: ext/ftp/ftp.c — PHP_FUNCTION(ftp_close) / PHP_FALIAS(ftp_quit)
 */
final class FtpCloseJitHelper
{
    public static function closeForHandle(int $handle): bool
    {
        if ($handle <= 0) {
            return true;
        }
        $fd = VmFtpCore::jitOwnedFdForLookupKey($handle);
        if (null !== $fd) {
            SocketsLibcThinAbi::close($fd);
        }
        VmFtpCore::releaseJitOwnedForLookupKey($handle);

        return true;
    }
}
