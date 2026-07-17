<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\TriggerErrorJitHelper;

/**
 * socket_import_stream() for compiled JIT/AOT modules (#9217, php-in-PHP).
 *
 * SSOT: {@see VmSocket::canImportStreamHandle()} / {@see VmSocket::registerJitImportedStream()}.
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_import_stream)
 */
final class SocketImportStreamJitHelper
{
    /** LLVM i32 ABI — 1 when stream handle can be wrapped as Socket */
    public static function canImportArgv(int $streamHandle): int
    {
        return VmSocket::canImportStreamHandle($streamHandle) ? 1 : 0;
    }

    public static function registerArgv(int $objAddr, int $streamHandle): void
    {
        VmSocket::registerJitImportedStream($objAddr, $streamHandle);
    }

    public static function warnUnableToImport(int $streamHandle = 0): void
    {
        TriggerErrorJitHelper::warning(self::failureMessage($streamHandle));
    }

    public static function failureMessage(int $streamHandle): string
    {
        return VmSocket::importFailureMessage($streamHandle);
    }
}
