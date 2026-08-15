<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * Marker / shrink-guard peer for socket_set_block NestedJIT (#31285).
 *
 * Fd resolve + fcntl live in {@see SocketCreateJitHelper::fdForHandleArgv} and
 * {@see \PHPCompiler\JIT\Builtin\SocketSetBlockRuntime} (LLVM fcntl bridges).
 * Kept so spine/inventory and unit guards can name a set_block-specific helper file.
 */
final class SocketSetBlockJitHelper
{
    /** @see SocketCreateJitHelper::fdForHandleArgv */
    public static function fdForHandleArgv(int $handle): int
    {
        return SocketCreateJitHelper::fdForHandleArgv($handle);
    }
}
