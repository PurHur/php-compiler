<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * fsync()/fdatasync() parity helpers (php-src ext/standard/file.c, issue #7339).
 */
final class VmStreamSync
{
    /** Zend uses the same C string for both; docref adds the function name prefix. */
    public const FSYNC_UNSYNCABLE_WARNING = "fsync(): Can't fsync this stream!";
    public const FDATASYNC_UNSYNCABLE_WARNING = "fdatasync(): Can't fsync this stream!";

    public static function triggerUnsyncableWarning(Frame $frame, string $function = 'fsync'): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $message = 'fdatasync' === $function
            ? self::FDATASYNC_UNSYNCABLE_WARNING
            : self::FSYNC_UNSYNCABLE_WARNING;
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame,
        );
    }

    /**
     * php-src: php_stream_sync_supported() before fsync/fdatasync (ext/standard/file.c).
     *
     * @param resource $fp
     */
    public static function isSupportedResource($fp): bool
    {
        $meta = @\stream_get_meta_data($fp);
        if (!\is_array($meta)) {
            return false;
        }
        $streamType = \strtoupper((string) ($meta['stream_type'] ?? ''));
        if (\in_array($streamType, ['MEMORY', 'TEMP', 'INPUT', 'OUTPUT'], true)) {
            return false;
        }
        $uri = \strtolower((string) ($meta['uri'] ?? ''));
        if (\str_starts_with($uri, 'php://')) {
            return false;
        }
        if (\in_array($streamType, ['TCP', 'UDP', 'UDG', 'UNIX', 'SSL', 'TLS', 'SOCKET'], true)) {
            return false;
        }

        return true;
    }

    public static function isSupported(int $handle): bool
    {
        if (!VmFs::isValidHandle($handle)) {
            return false;
        }
        $fp = VmFs::lookupResource($handle);
        if (null === $fp) {
            return false;
        }

        return self::isSupportedResource($fp);
    }
}
