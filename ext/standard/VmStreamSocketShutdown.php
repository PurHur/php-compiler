<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_shutdown() facade (issue #6043).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_shutdown)
 */
final class VmStreamSocketShutdown
{
    public static function shutdown(int $handle, int $how): bool
    {
        return VmStreamSocketShutdownPure::shutdown($handle, $how);
    }
}
