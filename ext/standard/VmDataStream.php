<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * data:// stream wrapper without host @fopen (#10263, ext/standard/php_data_wrapper.c).
 *
 * php-src: php_stream_data_wrapper — read-only in-memory payload from URI.
 */
final class VmDataStream
{
    public static function isSupportedUri(string $uri): bool
    {
        return VmDataUri::isDataUri($uri);
    }

    public static function open(string $uri, string $mode): int|false
    {
        // Zend opens data:// for write modes too (stream remains non-writable) — php_data_wrapper.c.
        // Reject only invalid fopen modes (#34744).
        if (!VmPhpMemoryStream::isValidMode($mode)) {
            return false;
        }
        $payload = VmDataUri::decode($uri);
        if (false === $payload) {
            return false;
        }

        return VmPhpMemoryStream::openWithBuffer($uri, $payload, $mode);
    }
}
