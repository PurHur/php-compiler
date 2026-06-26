<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_get_name() for compiled JIT/AOT modules (#12223).
 *
 * SSOT: {@see VmStreamSocketGetName}
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_get_name)
 */
final class StreamSocketGetNameJitHelper
{
    /** @return string|null null when name lookup fails */
    public static function getNameArgv(int $handle, int $wantPeer): ?string
    {
        $result = VmStreamSocketGetName::getName($handle, 0 !== $wantPeer);
        if (false === $result) {
            return null;
        }

        return $result;
    }
}
