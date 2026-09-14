<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/ftp advertisement — php-src ext/ftp/php_ftp.c (#3353, #7270, #19672, #20083).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 *
 * {@code function_exists('ftp_connect'|…)} / {@code FTP\Connection} / {@code extension_loaded('ftp')}
 * stay paired on the Zend 8.2 reference profile (FTP\Connection since 8.1). Gate is
 * {@see \PHPCompiler\CompilerVersion::supportsFtpConnection()} (8.1+), not stub-enum / stable-8.4.
 * Socket availability is no longer AND-ed into the advertise gate (same Vm*Native drop as #37708).
 */
final class FtpExtensionPolicy
{
    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    /** extension_loaded('ftp') — same gate as ftp_* / Ftp\Connection (#19672). */
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('ftp');
    }
}
