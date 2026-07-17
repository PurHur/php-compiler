<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmStreamSocketNative;

/**
 * ext/ftp advertisement — php-src ext/ftp/php_ftp.c (#3353, #7270, #19672, #20083).
 *
 * {@code function_exists('ftp_connect'|…)} / {@code FTP\Connection} / {@code extension_loaded('ftp')}
 * stay paired on the Zend 8.2 reference profile (FTP\Connection since 8.1). Gate is sockets +
 * {@see CompilerVersion::supportsFtpConnection()} (8.1+), not stub-enum / stable-8.4.
 */
final class FtpExtensionPolicy
{
    public static function advertisesBuiltins(): bool
    {
        return CompilerVersion::supportsFtpConnection() && VmStreamSocketNative::available();
    }

    /** extension_loaded('ftp') — same gate as ftp_* / Ftp\Connection (#19672). */
    public static function advertisesExtension(): bool
    {
        return self::advertisesBuiltins();
    }
}
