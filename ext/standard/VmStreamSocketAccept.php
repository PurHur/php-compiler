<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_accept() facade — {@see VmStreamSocketAcceptPure} SSOT (#15346).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_accept)
 */
final class VmStreamSocketAccept
{
    /**
     * @return array{0: int|false, 1: string}
     */
    public static function accept(int $serverHandle, ?float $timeout = null): array
    {
        return VmStreamSocketAcceptPure::accept($serverHandle, $timeout);
    }
}
