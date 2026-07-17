<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

/**
 * ZipArchive flags and error codes (php-src ext/zip/php_zip.c; issues #6413, #6414).
 */
final class ZipArchiveConstants
{
    public const CREATE = 1;

    public const EXCL = 2;

    public const CHECKCONS = 4;

    public const OVERWRITE = 8;

    public const ER_OK = 0;

    public const ER_MULTIDISK = 1;

    public const ER_RENAME = 2;

    public const ER_CLOSE = 3;

    public const ER_SEEK = 4;

    public const ER_READ = 5;

    public const ER_WRITE = 6;

    public const ER_CRC = 7;

    public const ER_ZIPCLOSED = 8;

    public const ER_NOENT = 9;

    public const ER_EXISTS = 10;

    public const ER_OPEN = 11;

    public const ER_TMPOPEN = 12;

    public const ER_ZLIB = 13;

    public const ER_MEMORY = 14;

    public const ER_CHANGED = 15;

    public const ER_COMPNOTSUPP = 16;

    public const ER_EOF = 17;

    public const ER_INVAL = 18;

    public const ER_NOZIP = 19;

    public const ER_INTERNAL = 20;

    public const ER_INCONS = 21;

    public const ER_REMOVE = 22;

    public const ER_DELETED = 23;

    /** Encryption methods — libzip ZIP_EM_* / php-src ZipArchive::EM_* (#19873). */
    public const EM_NONE = 0;

    public const EM_TRAD_PKWARE = 1;

    public const EM_AES_128 = 0x0101;

    public const EM_AES_192 = 0x0102;

    public const EM_AES_256 = 0x0103;

    public const EM_UNKNOWN = 0xffff;

    /** @var array<string, int> */
    public const CLASS_CONSTANTS = [
        'create' => self::CREATE,
        'excl' => self::EXCL,
        'checkcons' => self::CHECKCONS,
        'overwrite' => self::OVERWRITE,
        'em_none' => self::EM_NONE,
        'em_trad_pkware' => self::EM_TRAD_PKWARE,
        'em_aes_128' => self::EM_AES_128,
        'em_aes_192' => self::EM_AES_192,
        'em_aes_256' => self::EM_AES_256,
        'em_unknown' => self::EM_UNKNOWN,
        'er_ok' => self::ER_OK,
        'er_multidisk' => self::ER_MULTIDISK,
        'er_rename' => self::ER_RENAME,
        'er_close' => self::ER_CLOSE,
        'er_seek' => self::ER_SEEK,
        'er_read' => self::ER_READ,
        'er_write' => self::ER_WRITE,
        'er_crc' => self::ER_CRC,
        'er_zipclosed' => self::ER_ZIPCLOSED,
        'er_noent' => self::ER_NOENT,
        'er_exists' => self::ER_EXISTS,
        'er_open' => self::ER_OPEN,
        'er_tmpopen' => self::ER_TMPOPEN,
        'er_zlib' => self::ER_ZLIB,
        'er_memory' => self::ER_MEMORY,
        'er_changed' => self::ER_CHANGED,
        'er_compunsupported' => self::ER_COMPNOTSUPP,
        'er_eof' => self::ER_EOF,
        'er_inval' => self::ER_INVAL,
        'er_nozip' => self::ER_NOZIP,
        'er_internal' => self::ER_INTERNAL,
        'er_incons' => self::ER_INCONS,
        'er_remove' => self::ER_REMOVE,
        'er_deleted' => self::ER_DELETED,
    ];

    /** @var array<string, string> lowercase key => php-src constant casing */
    public const CLASS_CONSTANT_NAMES = [
        'create' => 'CREATE',
        'excl' => 'EXCL',
        'checkcons' => 'CHECKCONS',
        'overwrite' => 'OVERWRITE',
        'em_none' => 'EM_NONE',
        'em_trad_pkware' => 'EM_TRAD_PKWARE',
        'em_aes_128' => 'EM_AES_128',
        'em_aes_192' => 'EM_AES_192',
        'em_aes_256' => 'EM_AES_256',
        'em_unknown' => 'EM_UNKNOWN',
        'er_ok' => 'ER_OK',
        'er_multidisk' => 'ER_MULTIDISK',
        'er_rename' => 'ER_RENAME',
        'er_close' => 'ER_CLOSE',
        'er_seek' => 'ER_SEEK',
        'er_read' => 'ER_READ',
        'er_write' => 'ER_WRITE',
        'er_crc' => 'ER_CRC',
        'er_zipclosed' => 'ER_ZIPCLOSED',
        'er_noent' => 'ER_NOENT',
        'er_exists' => 'ER_EXISTS',
        'er_open' => 'ER_OPEN',
        'er_tmpopen' => 'ER_TMPOPEN',
        'er_zlib' => 'ER_ZLIB',
        'er_memory' => 'ER_MEMORY',
        'er_changed' => 'ER_CHANGED',
        'er_compunsupported' => 'ER_COMPNOTSUPP',
        'er_eof' => 'ER_EOF',
        'er_inval' => 'ER_INVAL',
        'er_nozip' => 'ER_NOZIP',
        'er_internal' => 'ER_INTERNAL',
        'er_incons' => 'ER_INCONS',
        'er_remove' => 'ER_REMOVE',
        'er_deleted' => 'ER_DELETED',
    ];

    public static function statusString(int $code): string
    {
        return match ($code) {
            self::ER_OK => 'No error',
            self::ER_MULTIDISK => 'Multi-disk zip archives not supported',
            self::ER_RENAME => 'Renaming temporary file failed',
            self::ER_CLOSE => 'Closing zip archive failed',
            self::ER_SEEK => 'Seek error',
            self::ER_READ => 'Read error',
            self::ER_WRITE => 'Write error',
            self::ER_CRC => 'CRC error',
            self::ER_ZIPCLOSED => 'Containing zip archive was closed',
            self::ER_NOENT => 'No such file',
            self::ER_EXISTS => 'File already exists',
            self::ER_OPEN => 'Can\'t open file',
            self::ER_TMPOPEN => 'Failure to create temporary file',
            self::ER_ZLIB => 'Zlib error',
            self::ER_MEMORY => 'Malloc failure',
            self::ER_CHANGED => 'Entry has been changed',
            self::ER_COMPNOTSUPP => 'Compression method not supported',
            self::ER_EOF => 'Premature EOF',
            self::ER_INVAL => 'Invalid argument',
            self::ER_NOZIP => 'Not a zip archive',
            self::ER_INTERNAL => 'Internal error',
            self::ER_INCONS => 'Zip archive inconsistent',
            self::ER_REMOVE => 'Can\'t remove file',
            self::ER_DELETED => 'Entry has been deleted',
            default => 'Unknown status ' . $code,
        };
    }
}
