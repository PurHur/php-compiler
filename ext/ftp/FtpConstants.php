<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

/**
 * FTP transfer/mode constants (php-src ext/ftp/php_ftp.c; #6762, #3353).
 */
final class FtpConstants
{
    public const FTP_ASCII = 1;
    public const FTP_TEXT = 1;
    public const FTP_BINARY = 2;
    public const FTP_IMAGE = 2;
    public const FTP_AUTORESUME = -1;
    public const FTP_TIMEOUT_SEC = 0;
    public const FTP_AUTOSEEK = 1;
    public const FTP_USEPASVADDRESS = 2;
    public const FTP_FAILED = 0;
    public const FTP_FINISHED = 1;
    public const FTP_MOREDATA = 2;

    /**
     * @return array<string, int>
     */
    public static function registeredConstants(): array
    {
        return [
            'FTP_ASCII' => self::FTP_ASCII,
            'FTP_TEXT' => self::FTP_TEXT,
            'FTP_BINARY' => self::FTP_BINARY,
            'FTP_IMAGE' => self::FTP_IMAGE,
            'FTP_AUTORESUME' => self::FTP_AUTORESUME,
            'FTP_TIMEOUT_SEC' => self::FTP_TIMEOUT_SEC,
            'FTP_AUTOSEEK' => self::FTP_AUTOSEEK,
            'FTP_USEPASVADDRESS' => self::FTP_USEPASVADDRESS,
            'FTP_FAILED' => self::FTP_FAILED,
            'FTP_FINISHED' => self::FTP_FINISHED,
            'FTP_MOREDATA' => self::FTP_MOREDATA,
        ];
    }
}
