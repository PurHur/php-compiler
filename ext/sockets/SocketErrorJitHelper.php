<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * NestedJIT helpers for socket_last_error / socket_clear_error (#31270).
 *
 * strerror(3) + host-lookup band live in {@see \PHPCompiler\JIT\Builtin\SocketErrorRuntime}
 * (LLVM libc / string constants — FFI is unavailable under NestedJIT thin AOT).
 * Per-socket errno maps: {@see VmSocket} (shared with create/close NestedJIT).
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_last_error|clear_error)
 */
final class SocketErrorJitHelper
{
    /**
     * @param int $handle Object address / NestedJIT handle; ≤0 → process-level errno
     */
    public static function lastErrorForHandle(int $handle): int
    {
        return VmSocket::lastErrorForLookupKey($handle);
    }

    /**
     * @param int $handle Object address / NestedJIT handle; ≤0 → clear process-level errno only
     */
    public static function clearErrorForHandle(int $handle): void
    {
        VmSocket::clearErrorOptionalForLookupKey($handle);
    }
}
