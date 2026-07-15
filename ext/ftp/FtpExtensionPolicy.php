<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmStreamSocketNative;

/**
 * ext/ftp advertisement — php-src ext/ftp/php_ftp.c (#3353, #7270).
 *
 * {@code function_exists('ftp_connect'|'ftp_fget'|…)} once handlers register (#3353, #6762);
 * {@code extension_loaded('ftp')} stays false until the module advertises as loaded.
 */
final class FtpExtensionPolicy
{
    public static function advertisesBuiltins(): bool
    {
        return CompilerVersion::supportsFtpConnection() && VmStreamSocketNative::available();
    }

    /** extension_loaded('ftp') — false until full ext/ftp parity ships (#3353 phase 2). */
    public static function advertisesExtension(): bool
    {
        return false;
    }
}
