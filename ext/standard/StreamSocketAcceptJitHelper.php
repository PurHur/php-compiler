<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_accept() for compiled JIT/AOT modules (#15346).
 *
 * SSOT: {@see VmStreamSocketAccept}
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_accept)
 */
final class StreamSocketAcceptJitHelper
{
    /** @return int 0 when accept fails (Zend false) */
    public static function acceptArgv(int $serverHandle, int $hasTimeout, float $timeout): int
    {
        $timeoutArg = 0 !== $hasTimeout ? $timeout : null;
        [$handle, $peername] = VmStreamSocketAccept::accept($serverHandle, $timeoutArg);
        unset($peername);
        if (false === $handle) {
            return 0;
        }

        return $handle;
    }
}
