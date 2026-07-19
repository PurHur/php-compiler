<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_recvfrom() facade (issue #21007).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_recvfrom)
 */
final class VmStreamSocketRecvfrom
{
    /**
     * @return array{0: string|false, 1: ?string}
     */
    public static function recvfrom(int $handle, int $length, int $flags, bool $wantAddress): array
    {
        return VmStreamSocketRecvfromPure::recvfrom($handle, $length, $flags, $wantAddress);
    }
}
