<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Stream metadata for VM — zend stream meta without host stream_get_meta_data() (#6007, #7908).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_get_meta_data)
 * JIT/AOT: lib/JIT/Builtin/StreamMetaJit.php (__compiler_stream_get_meta_data)
 */
final class VmStreamMeta
{
    /** @param resource $fp */
    public static function buildMetaArray(string $uri, $fp): array
    {
        $isPhp = \str_starts_with($uri, 'php://');
        $isPhpMemory = \str_starts_with($uri, 'php://memory')
            || \str_starts_with($uri, 'php://temp')
            || \str_starts_with($uri, 'php://fd/');

        return [
            'timed_out' => false,
            'blocked' => true,
            'eof' => \feof($fp),
            'unread_bytes' => 0,
            'stream_type' => $isPhpMemory ? 'MEMORY' : ($isPhp ? 'STDIO' : 'STDIO'),
            'mode' => $isPhpMemory ? 'w+b' : 'r+b',
            'seekable' => true,
            'uri' => $uri,
            'wrapper_type' => $isPhp ? 'PHP' : 'plainfile',
        ];
    }

    public static function isLocalUri(string $uri): bool
    {
        if ('' === $uri || !\str_contains($uri, '://')) {
            return true;
        }
        $scheme = \strtolower((string) \parse_url($uri, \PHP_URL_SCHEME));
        if ('' === $scheme || 'file' === $scheme) {
            return true;
        }
        if ('php' === $scheme) {
            return true;
        }

        return !\in_array($scheme, ['http', 'https', 'ftp', 'ftps'], true);
    }

    public static function supportsFilter(string $uri): bool
    {
        return !\in_array($uri, ['php://input', 'php://output', 'php://stdin'], true);
    }

    public static function supportsMetadata(string $uri): bool
    {
        if (\str_starts_with($uri, 'php://')) {
            return false;
        }

        return true;
    }

    public static function isSocketTransport(string $uri): bool
    {
        if ('' === $uri) {
            return false;
        }
        $scheme = \strtolower((string) \parse_url($uri, \PHP_URL_SCHEME));

        return \in_array($scheme, ['tcp', 'udp', 'unix', 'ssl', 'tls', 'socket'], true);
    }

    /**
     * php_stream_sync_supported() probe without host stream_get_meta_data() (#7339, #8118).
     */
    public static function supportsSync(string $uri): bool
    {
        $lower = \strtolower($uri);
        if (\str_starts_with($lower, 'php://memory')
            || \str_starts_with($lower, 'php://temp')
            || \str_starts_with($lower, 'php://fd/')) {
            return false;
        }
        if (\str_starts_with($lower, 'php://')) {
            return false;
        }

        return !self::isSocketTransport($uri);
    }

    /** @param resource $fp */
    public static function setBlocking($fp, bool $mode): bool
    {
        return @\stream_set_blocking($fp, $mode);
    }
}
