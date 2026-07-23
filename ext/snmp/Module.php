<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * snmp extension module entry (php-src ext/snmp/snmp.c; #6070, #22244).
 *
 * v1: snmpget/getnext/walk/realwalk/set + SNMP class registration. Without
 * Net-SNMP wire, ops return false + warning (php-src-strict unreachable-agent).
 * PHP-in-PHP only — no runtime/*.c growth.
 */
class Module extends ModuleAbstract
{
    private const SNMP_VERSION = '1.0';

    public function getExtensionName(): string
    {
        return 'snmp';
    }

    public function getExtensionVersion(): string
    {
        return self::SNMP_VERSION;
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!SnmpExtensionPolicy::advertisesExtension()) {
            return;
        }
        foreach (SnmpConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        if (!SnmpExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new snmpget(),
            new snmpgetnext(),
            new snmpwalk(),
            new snmprealwalk(),
            new snmpset(),
        ];
    }
}