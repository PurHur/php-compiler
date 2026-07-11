<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * VM stream_socket_client/server — {@see VmStreamSocketPure} SSOT, no libc socket FFI (#8953, #12858).
 *
 * php-src: ext/standard/streamsfuncs.c — stream_socket_client / stream_socket_server
 * JIT/AOT: unchanged — builtins route through this facade.
 */
final class VmStreamSocketNative
{
    public const STREAM_SERVER_BIND = 4;

    public const STREAM_SERVER_LISTEN = 8;

    public static function available(): bool
    {
        return VmStreamSocketPure::available();
    }

    /**
     * @return array{0: int|false, 1: int, 2: string, 3: ?int}
     */
    public static function client(
        string $remote,
        float $timeout,
        int $flags,
        ?Variable $contextVar = null
    ): array {
        return VmStreamSocketPure::client($remote, $timeout, $flags, $contextVar);
    }

    /**
     * @return array{0: int|false, 1: int, 2: string, 3: ?int}
     */
    public static function server(
        string $local,
        int $flags,
        ?Variable $contextVar = null
    ): array {
        return VmStreamSocketPure::server($local, $flags, $contextVar);
    }
}
