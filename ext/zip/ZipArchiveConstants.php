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

    /** Compression methods — libzip ZIP_CM_* / php-src ZipArchive::CM_* (#20363). */
    public const CM_DEFAULT = -1;

    public const CM_STORE = 0;

    public const CM_SHRINK = 1;

    public const CM_REDUCE_1 = 2;

    public const CM_REDUCE_2 = 3;

    public const CM_REDUCE_3 = 4;

    public const CM_REDUCE_4 = 5;

    public const CM_IMPLODE = 6;

    public const CM_DEFLATE = 8;

    public const CM_DEFLATE64 = 9;

    public const CM_PKWARE_IMPLODE = 10;

    public const CM_BZIP2 = 12;

    public const CM_LZMA = 14;

    public const CM_TERSE = 18;

    public const CM_LZ77 = 19;

    public const CM_LZMA2 = 33;

    public const CM_ZSTD = 93;

    public const CM_XZ = 95;

    public const CM_JPEG = 96;

    public const CM_WAVPACK = 97;

    public const CM_PPMD = 98;

    /** External attribute OS — libzip ZIP_OPSYS_* / php-src ZipArchive::OPSYS_* (#20363). */
    public const OPSYS_DOS = 0;

    public const OPSYS_AMIGA = 1;

    public const OPSYS_OPENVMS = 2;

    public const OPSYS_UNIX = 3;

    public const OPSYS_VM_CMS = 4;

    public const OPSYS_ATARI_ST = 5;

    public const OPSYS_OS_2 = 6;

    public const OPSYS_MACINTOSH = 7;

    public const OPSYS_Z_SYSTEM = 8;

    public const OPSYS_CPM = 9;

    public const OPSYS_WINDOWS_NTFS = 10;

    public const OPSYS_MVS = 11;

    public const OPSYS_VSE = 12;

    public const OPSYS_ACORN_RISC = 13;

    public const OPSYS_VFAT = 14;

    public const OPSYS_ALTERNATE_MVS = 15;

    public const OPSYS_BEOS = 16;

    public const OPSYS_TANDEM = 17;

    public const OPSYS_OS_400 = 18;

    public const OPSYS_OS_X = 19;

    public const OPSYS_DEFAULT = self::OPSYS_UNIX;

    /**
     * replaceFile / addFile length sentinel — libzip ZIP_LENGTH_TO_END (#20387).
     * When undefined in older libzip, php-src defines it as 0.
     */
    public const LENGTH_TO_END = 0;

    /** @var array<string, int> */
    public const CLASS_CONSTANTS = [
        'create' => self::CREATE,
        'excl' => self::EXCL,
        'checkcons' => self::CHECKCONS,
        'overwrite' => self::OVERWRITE,
        'length_to_end' => self::LENGTH_TO_END,
        'em_none' => self::EM_NONE,
        'em_trad_pkware' => self::EM_TRAD_PKWARE,
        'em_aes_128' => self::EM_AES_128,
        'em_aes_192' => self::EM_AES_192,
        'em_aes_256' => self::EM_AES_256,
        'em_unknown' => self::EM_UNKNOWN,
        'cm_default' => self::CM_DEFAULT,
        'cm_store' => self::CM_STORE,
        'cm_shrink' => self::CM_SHRINK,
        'cm_reduce_1' => self::CM_REDUCE_1,
        'cm_reduce_2' => self::CM_REDUCE_2,
        'cm_reduce_3' => self::CM_REDUCE_3,
        'cm_reduce_4' => self::CM_REDUCE_4,
        'cm_implode' => self::CM_IMPLODE,
        'cm_deflate' => self::CM_DEFLATE,
        'cm_deflate64' => self::CM_DEFLATE64,
        'cm_pkware_implode' => self::CM_PKWARE_IMPLODE,
        'cm_bzip2' => self::CM_BZIP2,
        'cm_lzma' => self::CM_LZMA,
        'cm_terse' => self::CM_TERSE,
        'cm_lz77' => self::CM_LZ77,
        'cm_lzma2' => self::CM_LZMA2,
        'cm_zstd' => self::CM_ZSTD,
        'cm_xz' => self::CM_XZ,
        'cm_jpeg' => self::CM_JPEG,
        'cm_wavpack' => self::CM_WAVPACK,
        'cm_ppmd' => self::CM_PPMD,
        'opsys_dos' => self::OPSYS_DOS,
        'opsys_amiga' => self::OPSYS_AMIGA,
        'opsys_openvms' => self::OPSYS_OPENVMS,
        'opsys_unix' => self::OPSYS_UNIX,
        'opsys_vm_cms' => self::OPSYS_VM_CMS,
        'opsys_atari_st' => self::OPSYS_ATARI_ST,
        'opsys_os_2' => self::OPSYS_OS_2,
        'opsys_macintosh' => self::OPSYS_MACINTOSH,
        'opsys_z_system' => self::OPSYS_Z_SYSTEM,
        'opsys_cpm' => self::OPSYS_CPM,
        'opsys_windows_ntfs' => self::OPSYS_WINDOWS_NTFS,
        'opsys_mvs' => self::OPSYS_MVS,
        'opsys_vse' => self::OPSYS_VSE,
        'opsys_acorn_risc' => self::OPSYS_ACORN_RISC,
        'opsys_vfat' => self::OPSYS_VFAT,
        'opsys_alternate_mvs' => self::OPSYS_ALTERNATE_MVS,
        'opsys_beos' => self::OPSYS_BEOS,
        'opsys_tandem' => self::OPSYS_TANDEM,
        'opsys_os_400' => self::OPSYS_OS_400,
        'opsys_os_x' => self::OPSYS_OS_X,
        'opsys_default' => self::OPSYS_DEFAULT,
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
        'length_to_end' => 'LENGTH_TO_END',
        'em_none' => 'EM_NONE',
        'em_trad_pkware' => 'EM_TRAD_PKWARE',
        'em_aes_128' => 'EM_AES_128',
        'em_aes_192' => 'EM_AES_192',
        'em_aes_256' => 'EM_AES_256',
        'em_unknown' => 'EM_UNKNOWN',
        'cm_default' => 'CM_DEFAULT',
        'cm_store' => 'CM_STORE',
        'cm_shrink' => 'CM_SHRINK',
        'cm_reduce_1' => 'CM_REDUCE_1',
        'cm_reduce_2' => 'CM_REDUCE_2',
        'cm_reduce_3' => 'CM_REDUCE_3',
        'cm_reduce_4' => 'CM_REDUCE_4',
        'cm_implode' => 'CM_IMPLODE',
        'cm_deflate' => 'CM_DEFLATE',
        'cm_deflate64' => 'CM_DEFLATE64',
        'cm_pkware_implode' => 'CM_PKWARE_IMPLODE',
        'cm_bzip2' => 'CM_BZIP2',
        'cm_lzma' => 'CM_LZMA',
        'cm_terse' => 'CM_TERSE',
        'cm_lz77' => 'CM_LZ77',
        'cm_lzma2' => 'CM_LZMA2',
        'cm_zstd' => 'CM_ZSTD',
        'cm_xz' => 'CM_XZ',
        'cm_jpeg' => 'CM_JPEG',
        'cm_wavpack' => 'CM_WAVPACK',
        'cm_ppmd' => 'CM_PPMD',
        'opsys_dos' => 'OPSYS_DOS',
        'opsys_amiga' => 'OPSYS_AMIGA',
        'opsys_openvms' => 'OPSYS_OPENVMS',
        'opsys_unix' => 'OPSYS_UNIX',
        'opsys_vm_cms' => 'OPSYS_VM_CMS',
        'opsys_atari_st' => 'OPSYS_ATARI_ST',
        'opsys_os_2' => 'OPSYS_OS_2',
        'opsys_macintosh' => 'OPSYS_MACINTOSH',
        'opsys_z_system' => 'OPSYS_Z_SYSTEM',
        'opsys_cpm' => 'OPSYS_CPM',
        'opsys_windows_ntfs' => 'OPSYS_WINDOWS_NTFS',
        'opsys_mvs' => 'OPSYS_MVS',
        'opsys_vse' => 'OPSYS_VSE',
        'opsys_acorn_risc' => 'OPSYS_ACORN_RISC',
        'opsys_vfat' => 'OPSYS_VFAT',
        'opsys_alternate_mvs' => 'OPSYS_ALTERNATE_MVS',
        'opsys_beos' => 'OPSYS_BEOS',
        'opsys_tandem' => 'OPSYS_TANDEM',
        'opsys_os_400' => 'OPSYS_OS_400',
        'opsys_os_x' => 'OPSYS_OS_X',
        'opsys_default' => 'OPSYS_DEFAULT',
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
