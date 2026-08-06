<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Stream metadata for VM — zend stream meta without host stream_get_meta_data() (#6007, #7908).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_get_meta_data)
 * JIT/AOT: ext/standard/JitStreamMetaKernel.php (__compiler_stream_get_meta_data)
 */
final class VmStreamMeta
{
    /**
     * @param resource|null $fp host FILE* for plainfile streams; null for VmPhpMemoryStream et al.
     */
    public static function buildMetaArray(string $uri, $fp = null, ?bool $eofOverride = null, ?string $mode = null, ?bool $blocked = null): array
    {
        $isPhp = \str_starts_with($uri, 'php://');
        $isData = VmDataUri::isDataUri($uri);
        $isPhpMemory = \str_starts_with($uri, 'php://memory')
            || \str_starts_with($uri, 'php://temp')
            || \str_starts_with($uri, 'php://fd/');

        $socketType = self::streamTypeForUri($uri);
        $phpNativeStreamType = self::phpNativeStreamType($uri);
        $stdioInheritedType = self::stdioInheritedStreamType($uri, $fp);
        $eof = null !== $eofOverride
            ? $eofOverride
            : (\is_resource($fp) ? \feof($fp) : false);
        $reportedMode = null !== $mode ? $mode : self::defaultReportedMode($uri, $isPhpMemory);

        // php-src php_stream_temp — no timed_out/blocked/eof keys (main/streams/php_stream_temp.c; #17928).
        if (\str_starts_with($uri, 'php://temp')) {
            return [
                'wrapper_type' => 'PHP',
                'stream_type' => $socketType ?? $phpNativeStreamType ?? 'TEMP',
                'mode' => $reportedMode,
                'unread_bytes' => 0,
                'seekable' => self::supportsSeekable($uri),
                'uri' => $uri,
            ];
        }

        // php-src ext/standard/php_stream_rfc2397.c — data:// wrapper metadata (#18580).
        if ($isData) {
            return [
                'wrapper_type' => 'RFC2397',
                'stream_type' => 'RFC2397',
                'mode' => $reportedMode,
                'unread_bytes' => 0,
                'seekable' => self::supportsSeekable($uri),
                'uri' => $uri,
            ];
        }

        // php-src main/streams/userspace.c — stream_get_meta_data labels (#25993).
        if (VmStreamWrapperRegistry::isCustomProtocol($uri)) {
            return [
                'timed_out' => false,
                'blocked' => $blocked ?? true,
                'eof' => $eof,
                'wrapper_type' => 'user-space',
                'stream_type' => 'user-space',
                'mode' => $reportedMode,
                'unread_bytes' => 0,
                'seekable' => self::supportsSeekable($uri),
                'uri' => $uri,
            ];
        }

        // php-src xport sockets (xp_socket.c / openssl xp_ssl.c) — no wrapper_type/uri (#28139).
        if (null !== $socketType) {
            return [
                'timed_out' => false,
                'blocked' => $blocked ?? true,
                'eof' => $eof,
                'stream_type' => $socketType,
                'mode' => $reportedMode,
                'unread_bytes' => 0,
                'seekable' => false,
            ];
        }

        // php-src ext/standard/streams.c — array_add_next insertion order (#17428).
        return [
            'timed_out' => false,
            'blocked' => $blocked ?? true,
            'eof' => $eof,
            'wrapper_type' => self::wrapperTypeForUri($uri),
            'stream_type' => $stdioInheritedType ?? $phpNativeStreamType ?? ($isPhp ? 'STDIO' : 'STDIO'),
            'mode' => $reportedMode,
            'unread_bytes' => 0,
            'seekable' => self::supportsSeekable($uri),
            'uri' => $uri,
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

    /**
     * php-src ext/standard/streams.c — wrapper_type in stream_get_meta_data (#18580, #18581, #25993).
     */
    public static function wrapperTypeForUri(string $uri): string
    {
        if (VmStreamWrapperRegistry::isCustomProtocol($uri)) {
            return 'user-space';
        }
        if (\str_starts_with($uri, 'php://')) {
            return 'PHP';
        }
        if (\str_starts_with($uri, 'data://')) {
            return 'RFC2397';
        }

        return 'plainfile';
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

    /** stream_supports_lock() — php-src php_stream_supports_lock / plainfile+stdio (#6039, #19462). */
    public static function supportsLock(string $uri, ?string $mode = null): bool
    {
        unset($mode);
        if ('' === $uri) {
            return false;
        }
        $lower = \strtolower($uri);
        if (VmPhpMemoryStream::isSupportedUri($uri)) {
            return false;
        }
        if (\in_array($lower, ['php://input', 'php://output', 'php://stdin', 'php://stdout', 'php://stderr'], true)) {
            return false;
        }
        if (self::isSocketTransport($uri)) {
            return false;
        }
        if (\str_starts_with($lower, 'data:') || \str_starts_with($lower, 'php://filter')) {
            return false;
        }

        return true;
    }

    /** stream_supports(..., STREAM_META_SEEKABLE) — php-src php_stream_can_seek (PHP 8.4+). */
    public static function supportsSeekable(string $uri): bool
    {
        $lower = \strtolower($uri);
        if (\str_starts_with($lower, 'php://input')
            || \str_starts_with($lower, 'php://output')
            || 'php://stdin' === $lower
            || 'php://stdout' === $lower
            || 'php://stderr' === $lower) {
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

    /** stream_supports(..., 'read') — php-src read op probe (PHP 8.4, issue #16329). */
    public static function supportsRead(string $uri, string $mode): bool
    {
        $lower = \strtolower($uri);
        if ('php://output' === $lower) {
            return false;
        }

        return self::modeAllowsRead($mode);
    }

    /** stream_supports(..., 'write') — php-src write op probe (PHP 8.4, issue #16329). */
    public static function supportsWrite(string $uri, string $mode): bool
    {
        $lower = \strtolower($uri);
        if (\str_starts_with($lower, 'php://input') || 'php://stdin' === $lower) {
            return false;
        }

        return self::modeAllowsWrite($mode);
    }

    private static function modeAllowsRead(string $mode): bool
    {
        $stripped = \strtr(\strtolower($mode), ['b' => '', 't' => '']);

        return \str_contains($stripped, 'r') || \str_contains($stripped, '+') || \str_contains($stripped, 'a');
    }

    private static function modeAllowsWrite(string $mode): bool
    {
        $stripped = \strtr(\strtolower($mode), ['b' => '', 't' => '']);

        return \str_contains($stripped, 'w') || \str_contains($stripped, '+') || \str_contains($stripped, 'a');
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
     * php://stdin|stdout|stderr may wrap an inherited socket fd — mirror host stream_type (#19129).
     *
     * php-src: main/streams/php_stream_stdio.c — php_stream_stdio_cast / is_socket probe
     *
     * @param resource|null $fp host stream adopted by {@see VmFsStdioPure::openDupFd()}
     */
    public static function stdioInheritedStreamType(string $uri, $fp): ?string
    {
        if (!VmFsStdio::isStdioUri($uri) || !\is_resource($fp)) {
            return null;
        }
        $meta = @\stream_get_meta_data($fp);
        if (!\is_array($meta)) {
            return null;
        }
        $type = $meta['stream_type'] ?? null;
        if (!\is_string($type) || '' === $type || 'STDIO' === $type) {
            return null;
        }

        return self::normalizeHostStreamType($type);
    }

    /** Map host wrapper labels to php-src stream_type strings consumed by socket_import_stream. */
    private static function normalizeHostStreamType(string $type): string
    {
        return match ($type) {
            'generic_socket' => 'unix_socket',
            default => $type,
        };
    }

    /**
     * php-src stream_type for php://memory / php://temp / php://fd (main/streams/php_stream_memory.c, php_stream_temp.c).
     */
    public static function phpNativeStreamType(string $uri): ?string
    {
        if (\str_starts_with($uri, 'php://temp')) {
            return 'TEMP';
        }
        if (\str_starts_with($uri, 'php://memory') || \str_starts_with($uri, 'php://fd/')) {
            return 'MEMORY';
        }

        return null;
    }

    /**
     * php-src stream_type strings for socket transports (ext/standard/streams.c; #6203, #8202, #28139).
     *
     * When OpenSSL is loaded, tcp uses php_openssl_socket_ops labelled "tcp_socket/ssl"
     * (ext/openssl/xp_ssl.c) even for plain TCP — match host Zend.
     */
    public static function streamTypeForUri(string $uri): ?string
    {
        if ('' === $uri) {
            return null;
        }
        $scheme = \strtolower((string) \parse_url($uri, \PHP_URL_SCHEME));

        return match ($scheme) {
            'tcp' => self::tcpSocketStreamType(),
            'udp' => 'udp_socket',
            'unix' => 'unix_socket',
            'ssl', 'tls' => 'ssl_socket',
            default => null,
        };
    }

    /** php-src: openssl overrides tcp factory → "tcp_socket/ssl" (#28139). */
    private static function tcpSocketStreamType(): string
    {
        if (\extension_loaded('openssl')) {
            return 'tcp_socket/ssl';
        }

        return 'tcp_socket';
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
