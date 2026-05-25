<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** VM helpers for multipart upload temp files (issues #52, #2005). */
final class VmUpload
{
    private const UPLOAD_PREFIX = 'phpc_upload_';

    /**
     * True when $path is a regular file under the system temp dir created by our multipart parser.
     */
    public static function isUploadedTempPath(string $path): bool
    {
        if ('' === $path || str_contains($path, "\0")) {
            return false;
        }
        if (!is_file($path)) {
            return false;
        }
        $real = realpath($path);
        if (false === $real) {
            return false;
        }
        $tmpdir = realpath(sys_get_temp_dir());
        if (false === $tmpdir) {
            return false;
        }
        $prefix = $tmpdir.\DIRECTORY_SEPARATOR;
        if ($real !== $tmpdir && !str_starts_with($real, $prefix)) {
            return false;
        }
        $base = basename($real);

        return str_starts_with($base, self::UPLOAD_PREFIX);
    }

    /**
     * Reject obvious path traversal in the destination (issue #2005).
     */
    public static function isSafeDestinationPath(string $path): bool
    {
        if ('' === $path || str_contains($path, "\0")) {
            return false;
        }
        if (preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $path)) {
            return false;
        }

        return true;
    }

    public static function moveUploadedFile(string $from, string $to): bool
    {
        if (!self::isUploadedTempPath($from) || !self::isSafeDestinationPath($to)) {
            return false;
        }

        return @rename($from, $to);
    }
}
