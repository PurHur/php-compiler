<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

/**
 * FILEINFO_* constants (php-src ext/fileinfo/fileinfo.stub.php; #3366).
 *
 * Values match Zend 8.2+ / libmagic on Linux.
 */
final class FileinfoConstants
{
    public const FILEINFO_NONE = 0;
    public const FILEINFO_SYMLINK = 2;
    public const FILEINFO_MIME_TYPE = 16;
    public const FILEINFO_DEVICES = 8;
    public const FILEINFO_CONTINUE = 32;
    public const FILEINFO_PRESERVE_ATIME = 128;
    public const FILEINFO_RAW = 256;
    public const FILEINFO_MIME_ENCODING = 1024;
    public const FILEINFO_MIME = 1040; // MIME_TYPE | MIME_ENCODING
    public const FILEINFO_APPLE = 2048;
    public const FILEINFO_EXTENSION = 16777216;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'FILEINFO_NONE' => self::FILEINFO_NONE,
            'FILEINFO_SYMLINK' => self::FILEINFO_SYMLINK,
            'FILEINFO_MIME' => self::FILEINFO_MIME,
            'FILEINFO_MIME_TYPE' => self::FILEINFO_MIME_TYPE,
            'FILEINFO_MIME_ENCODING' => self::FILEINFO_MIME_ENCODING,
            'FILEINFO_DEVICES' => self::FILEINFO_DEVICES,
            'FILEINFO_CONTINUE' => self::FILEINFO_CONTINUE,
            'FILEINFO_PRESERVE_ATIME' => self::FILEINFO_PRESERVE_ATIME,
            'FILEINFO_RAW' => self::FILEINFO_RAW,
            'FILEINFO_APPLE' => self::FILEINFO_APPLE,
            'FILEINFO_EXTENSION' => self::FILEINFO_EXTENSION,
        ];
    }
}
