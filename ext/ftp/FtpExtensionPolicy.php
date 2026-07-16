<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmStreamSocketNative;

/**
 * ext/ftp advertisement — php-src ext/ftp/php_ftp.c (#3353, #7270, #19672).
 *
 * {@code function_exists('ftp_connect'|…)} / {@code Ftp\Connection} / {@code extension_loaded('ftp')}
 * stay paired — Zend never splits the module flag from the procedural + Connection surface.
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
