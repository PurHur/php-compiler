<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\sockets\VmSockets;

/**
 * stream_socket_shutdown() — socket half/full close (issue #6043).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_shutdown)
 */
final class VmStreamSocketShutdownPure
{
    public static function shutdown(int $handle, int $how): bool
    {
        if ($how < 0 || $how > 2) {
            return false;
        }

        $fd = VmFs::socketFdForHandle($handle);
        if (null !== $fd && $fd >= 0 && VmSockets::isShutdownSupported()) {
            return VmSockets::shutdownForFd($fd, $how);
        }

        if (\function_exists('stream_socket_shutdown')) {
            $fp = VmFs::hostStreamResource($handle);
            if (\is_resource($fp)) {
                return @\stream_socket_shutdown($fp, $how);
            }
        }

        return false;
    }
}
