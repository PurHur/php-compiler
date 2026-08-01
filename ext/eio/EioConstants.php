<?php

declare(strict_types=1);

namespace PHPCompiler\ext\eio;

/** PECL eio priority / open flags (#6442). */
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
        ];
    }
}
