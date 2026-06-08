<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\ext\standard\VmFs;

/**
 * CGI multipart upload temp paths — PHP registry replacing phpc_upload_temp.c (#6342).
 *
 * php-src: main/rfc1867.c upload temp lifecycle; ext/standard/basic_functions.c move_uploaded_file.
 */
final class UploadTemp
{
    public static function prefix(): string
    {
        return VmFs::UPLOAD_TEMP_PREFIX;
    }

    public static function tempDir(): string
    {
        return VmFs::tempDir();
    }

    /** @return string|false */
    public static function createTempFile()
    {
        return tempnam(self::tempDir(), self::prefix());
    }

    public static function isValidPath(string $path): bool
    {
        return VmFs::isValidUploadTempPath($path);
    }

    public static function moveUploadedFile(string $from, string $to): bool
    {
        return VmFs::moveUploadedFile($from, $to);
    }
}
