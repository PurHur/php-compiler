<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Persistent socket URI helpers for pfsockopen() (#3384).
 *
 * Connection caching and reuse are delegated to host {@see \pfsockopen()} /
 * php-src php_stream_popen persistent list — this class only builds stream URIs
 * for {@see VmFs::adoptStreamResource()}.
 */
final class VmPersistentSocket
{
    public static function remoteUri(string $hostname, int $port): string
    {
        if ($port >= 0) {
            return 'tcp://'.$hostname.':'.$port;
        }

        return 'tcp://'.$hostname;
    }
}
