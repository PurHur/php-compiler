<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\CompilerVersion;

/**
 * ext/snmp surface advertisement — php-src ext/snmp/snmp.c (#6070).
 *
 * Withheld on the reference profile (Zend 8.2 harness typically has no net-snmp
 * PHP extension). Enable via {@code PHP_COMPILER_PROFILE=8.4}.
 */
final class SnmpExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsSnmp();
    }
}