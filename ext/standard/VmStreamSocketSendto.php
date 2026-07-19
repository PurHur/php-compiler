<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_sendto() facade (issue #21008).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_sendto)
 */
final class VmStreamSocketSendto
{
    public static function sendto(int $handle, string $data, int $flags, ?string $address): int|false
    {
        return VmStreamSocketSendtoPure::sendto($handle, $data, $flags, $address);
    }
}
