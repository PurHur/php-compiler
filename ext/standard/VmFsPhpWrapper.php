<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * php:// stream wrapper unlink/rename rejection messages (php-src userspace.c; #18404).
 *
 * @see https://github.com/php/php-src/blob/master/main/streams/userspace.c
 * @see https://github.com/php/php-src/blob/master/ext/standard/filestat.c php_unlink / php_rename
 */
final class VmFsPhpWrapper
{
    public static function isPhpWrapperPath(string $path): bool
    {
        return \str_starts_with($path, 'php://');
    }

    public static function unlinkWarningMessage(): string
    {
        return 'unlink(): PHP does not allow unlinking';
    }

    public static function renameWarningMessage(string $from, string $to): ?string
    {
        $fromPhp = self::isPhpWrapperPath($from);
        $toPhp = self::isPhpWrapperPath($to);
        if ($fromPhp && $toPhp) {
            return 'rename(): PHP wrapper does not support renaming';
        }
        if ($fromPhp || $toPhp) {
            return 'rename(): Cannot rename a file across wrapper types';
        }

        return null;
    }
}
