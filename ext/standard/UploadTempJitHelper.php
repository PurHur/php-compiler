<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Upload temp validation for compiled JIT/AOT modules (#9799, php-in-PHP).
 *
 * VM SSOT: {@see VmFs::isValidUploadTempPath()}, {@see VmFs::moveUploadedFile()}.
 * php-src: ext/standard/basic_functions.c — is_uploaded_file, move_uploaded_file
 */
final class UploadTempJitHelper
{
    public static function pathHasParentTraversal(string $path): int
    {
        return VmFs::pathHasParentTraversal($path) ? 1 : 0;
    }

    public static function tempDir(): string
    {
        return VmFs::tempDir();
    }

    public static function isValidTemp(string $path): int
    {
        if ('' === $path) {
            return 0;
        }

        return VmFs::isValidUploadTempPath($path) ? 1 : 0;
    }

    public static function isUploadedFile(string $path): int
    {
        return self::isValidTemp($path);
    }

    public static function moveUploadedFile(string $from, string $to): int
    {
        return VmFs::moveUploadedFile($from, $to) ? 1 : 0;
    }
}
