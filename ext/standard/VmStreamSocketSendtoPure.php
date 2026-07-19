<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_sendto() — host transport fallback (issue #21008).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_sendto)
 */
final class VmStreamSocketSendtoPure
{
    public static function sendto(int $handle, string $data, int $flags, ?string $address): int|false
    {
        if (!\function_exists('stream_socket_sendto')) {
            return false;
        }

        $fp = VmFs::hostStreamResource($handle);
        if (!\is_resource($fp)) {
            return false;
        }

        if (null !== $address && '' !== $address) {
            $n = @\stream_socket_sendto($fp, $data, $flags, $address);
        } else {
            $n = @\stream_socket_sendto($fp, $data, $flags);
        }

        if (false === $n) {
            return false;
        }

        return (int) $n;
    }
}
