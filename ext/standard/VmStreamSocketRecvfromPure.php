<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_recvfrom() — host transport fallback (issue #21007).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_recvfrom)
 */
final class VmStreamSocketRecvfromPure
{
    /**
     * @return array{0: string|false, 1: ?string} payload + optional remote address
     */
    public static function recvfrom(int $handle, int $length, int $flags, bool $wantAddress): array
    {
        if (!\function_exists('stream_socket_recvfrom')) {
            return [false, null];
        }

        $fp = VmFs::hostStreamResource($handle);
        if (!\is_resource($fp)) {
            return [false, null];
        }

        if ($wantAddress) {
            $address = null;
            $buf = @\stream_socket_recvfrom($fp, $length, $flags, $address);
            if (false === $buf) {
                return [false, null];
            }

            return [$buf, \is_string($address) ? $address : null];
        }

        $buf = @\stream_socket_recvfrom($fp, $length, $flags);
        if (false === $buf) {
            return [false, null];
        }

        return [$buf, null];
    }
}
