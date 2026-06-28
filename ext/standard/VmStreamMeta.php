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
    /**
     * @param resource|null $fp host FILE* for plainfile streams; null for VmPhpMemoryStream et al.
     */
    public static function buildMetaArray(string $uri, $fp = null, ?bool $eofOverride = null, ?string $mode = null): array
    {
        $isPhp = \str_starts_with($uri, 'php://');
        $isPhpMemory = \str_starts_with($uri, 'php://memory')
            || \str_starts_with($uri, 'php://temp')
            || \str_starts_with($uri, 'php://fd/');

        $socketType = self::streamTypeForUri($uri);
        $eof = null !== $eofOverride ? $eofOverride : \feof($fp);
        $reportedMode = null !== $mode ? $mode : self::defaultReportedMode($uri, $isPhpMemory);

        return [
            'timed_out' => false,
            'blocked' => true,
            'eof' => $eof,
            'unread_bytes' => 0,
            'stream_type' => $socketType ?? ($isPhpMemory ? 'MEMORY' : ($isPhp ? 'STDIO' : 'STDIO')),
            'mode' => $reportedMode,
            'seekable' => true,
            'uri' => $uri,
            'wrapper_type' => $isPhp ? 'PHP' : 'plainfile',
        ];
    }

    /**
     * User-facing stream_get_meta_data()['mode'] — php-src php_stream mode normalization (#13021).
     */
    public static function userFacingMode(string $uri, ?string $userMode): string
    {
        if (null === $userMode || '' === $userMode) {
            return self::defaultReportedMode($uri, self::isPhpMemoryUri($uri));
        }
        if (self::isPhpMemoryUri($uri)) {
            return self::memoryStreamReportedMode($userMode);
        }

        return $userMode;
    }

    private static function isPhpMemoryUri(string $uri): bool
    {
        return \str_starts_with($uri, 'php://memory')
            || \str_starts_with($uri, 'php://temp')
            || \str_starts_with($uri, 'php://fd/');
    }

    private static function defaultReportedMode(string $uri, bool $isPhpMemory): string
    {
        return $isPhpMemory ? 'w+b' : 'r+b';
    }

    /**
     * php-src php_stream_memory metadata mode mapping (main/streams/php_stream_memory.c).
     */
    private static function memoryStreamReportedMode(string $userMode): string
    {
        $lower = \strtolower(\strtr($userMode, ['t' => '']));
        $stripped = \strtr($lower, ['b' => '']);
        if (\str_contains($stripped, 'a')) {
            return 'a+b';
        }
        if (\str_contains($stripped, 'w') || \str_contains($stripped, '+')) {
            return 'w+b';
        }
        if ('r' === $stripped) {
            return 'rb';
        }

        return \str_contains($userMode, 'b') ? 'w+b' : 'rb';
    }

    /** EOF for VM-native stream handles without host FILE* (php://memory, php://input, user streams). */
    public static function eofForNativeHandle(int $handle): bool
    {
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return VmPhpMemoryStream::eof($handle);
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return VmPhpInputOutputStream::eof($handle);
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            return VmPhpFdStream::eof($handle);
        }
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::feof($handle);
        }

        return true;
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

    /** stream_supports(..., STREAM_META_SEEKABLE) — php-src php_stream_can_seek (PHP 8.4+). */
    public static function supportsSeekable(string $uri): bool
    {
        $lower = \strtolower($uri);
        if (\str_starts_with($lower, 'php://input')
            || \str_starts_with($lower, 'php://output')
            || 'php://stdin' === $lower) {
            return false;
        }
        if (self::isSocketTransport($uri)) {
            return false;
        }

        return true;
    }

    /** stream_supports(..., STREAM_SUPPORT_TELL) — php-src php_stream_tell (issue #11702). */
    public static function supportsTell(string $uri): bool
    {
        $lower = \strtolower($uri);
        if (\str_starts_with($lower, 'php://input')
            || \str_starts_with($lower, 'php://output')
            || 'php://stdin' === $lower) {
            return false;
        }

        return true;
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
        return null !== self::streamTypeForUri($uri);
    }

    /**
     * php-src stream_type strings for socket transports (ext/standard/streams.c; #6203, #8202).
     */
    public static function streamTypeForUri(string $uri): ?string
    {
        if ('' === $uri) {
            return null;
        }
        $scheme = \strtolower((string) \parse_url($uri, \PHP_URL_SCHEME));

        return match ($scheme) {
            'tcp' => 'tcp_socket',
            'udp' => 'udp_socket',
            'unix' => 'unix_socket',
            'ssl', 'tls' => 'ssl_socket',
            default => null,
        };
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
        return VmStreamBlockingNative::setBlockingForHostResource($fp, $mode);
    }
}
