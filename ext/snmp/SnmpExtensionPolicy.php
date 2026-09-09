<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/snmp surface advertisement — php-src ext/snmp/snmp.c (#6070). Folded to ext.json advertise (#36204).
 */
final class SnmpExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('snmp');
    }
}
