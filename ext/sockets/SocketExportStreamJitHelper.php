<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\TriggerErrorJitHelper;

/**
 * socket_export_stream() for compiled JIT/AOT modules (#6349, php-in-PHP).
 *
 * SSOT: {@see VmSocket::streamHandleForLookupKey()} (creates fd stream for socket_create; #22542).
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_export_stream)
 */
final class SocketExportStreamJitHelper
{
    /** LLVM i64 ABI — VmFs stream handle, or 0 when export fails */
    public static function streamHandleForSocket(int $lookupKey): int
    {
        return VmSocket::streamHandleForLookupKey($lookupKey) ?? 0;
    }

    public static function warnUnableToExport(): void
    {
        TriggerErrorJitHelper::warning('socket_export_stream(): Unable to export socket');
    }
}
