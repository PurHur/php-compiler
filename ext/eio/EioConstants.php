<?php

declare(strict_types=1);

namespace PHPCompiler\ext\eio;

/**
 * PECL eio priority / open / readdir / request-type flags (#6442, #27837).
 *
 * EIO_READ / EIO_STAT / … match libeio request-type enum ordinals (eio.h).
 * EIO_READDIR_* / EIO_DT_* match libeio flag enums registered in php_eio.c MINIT.
 */
final class EioConstants
{
    public const EIO_PRI_MIN = -1;
    public const EIO_PRI_DEFAULT = 0;
    public const EIO_PRI_MAX = 1;

    public const EIO_O_RDONLY = 0;
    public const EIO_O_WRONLY = 1;
    public const EIO_O_RDWR = 2;
    public const EIO_O_CREAT = 64;
    public const EIO_O_TRUNC = 512;
    public const EIO_O_APPEND = 1024;

    /** libeio request type EIO_READ (eio.h enum). */
    public const EIO_READ = 6;

    public const EIO_READDIR_DENTS = 0x01;
    public const EIO_READDIR_DIRS_FIRST = 0x02;
    public const EIO_READDIR_STAT_ORDER = 0x04;
    public const EIO_READDIR_FOUND_UNKNOWN = 0x80;

    public const EIO_DT_UNKNOWN = 0;
    public const EIO_DT_FIFO = 1;
    public const EIO_DT_CHR = 2;
    public const EIO_DT_DIR = 4;
    public const EIO_DT_BLK = 6;
    public const EIO_DT_REG = 8;
    public const EIO_DT_LNK = 10;
    public const EIO_DT_SOCK = 12;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'EIO_PRI_MIN' => self::EIO_PRI_MIN,
            'EIO_PRI_DEFAULT' => self::EIO_PRI_DEFAULT,
            'EIO_PRI_MAX' => self::EIO_PRI_MAX,
            'EIO_O_RDONLY' => self::EIO_O_RDONLY,
            'EIO_O_WRONLY' => self::EIO_O_WRONLY,
            'EIO_O_RDWR' => self::EIO_O_RDWR,
            'EIO_O_CREAT' => self::EIO_O_CREAT,
            'EIO_O_TRUNC' => self::EIO_O_TRUNC,
            'EIO_O_APPEND' => self::EIO_O_APPEND,
            'EIO_READ' => self::EIO_READ,
            'EIO_READDIR_DENTS' => self::EIO_READDIR_DENTS,
            'EIO_READDIR_DIRS_FIRST' => self::EIO_READDIR_DIRS_FIRST,
            'EIO_READDIR_STAT_ORDER' => self::EIO_READDIR_STAT_ORDER,
            'EIO_READDIR_FOUND_UNKNOWN' => self::EIO_READDIR_FOUND_UNKNOWN,
            'EIO_DT_UNKNOWN' => self::EIO_DT_UNKNOWN,
            'EIO_DT_FIFO' => self::EIO_DT_FIFO,
            'EIO_DT_CHR' => self::EIO_DT_CHR,
            'EIO_DT_DIR' => self::EIO_DT_DIR,
            'EIO_DT_BLK' => self::EIO_DT_BLK,
            'EIO_DT_REG' => self::EIO_DT_REG,
            'EIO_DT_LNK' => self::EIO_DT_LNK,
            'EIO_DT_SOCK' => self::EIO_DT_SOCK,
        ];
    }
}
