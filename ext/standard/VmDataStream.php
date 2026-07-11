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
        if (!self::isReadMode($mode)) {
            return false;
        }
        $payload = VmDataUri::decode($uri);
        if (false === $payload) {
            return false;
        }

        return VmPhpMemoryStream::openWithBuffer($uri, $payload, $mode);
    }

    private static function isReadMode(string $mode): bool
    {
        if (!VmPhpMemoryStream::isValidMode($mode)) {
            return false;
        }
        $normalized = \strtolower(\strtr($mode, ['b' => '', 't' => '']));
        if ('' === $normalized) {
            return false;
        }

        return 'r' === $normalized[0];
    }
}
