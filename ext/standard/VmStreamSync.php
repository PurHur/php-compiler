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
     * Uses {@see VmStreamMeta::supportsSync()} + {@see VmFs::handleUri()} — no host
     * stream_get_meta_data delegation (#8118).
     */
    public static function isSupported(int $handle): bool
    {
        if ($handle <= 0) {
            return false;
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            return VmStreamMeta::supportsSync(VmFs::handleUri($handle));
        }
        if (VmFs::isValidHandle($handle)) {
            return VmStreamMeta::supportsSync(VmFs::handleUri($handle));
        }
        $uri = VmFs::handleUri($handle);
        if ('' !== $uri) {
            return VmStreamMeta::supportsSync($uri);
        }

        return false;
    }
}
