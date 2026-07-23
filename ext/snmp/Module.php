<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * snmp extension module entry (php-src ext/snmp/snmp.c; #6070, #22244, #22250).
 *
 * SNMPv1/v2c/v3 procedural surface + SNMP class + print/MIB/valueretrieval helpers.
 * Without Net-SNMP wire, query ops return false + warning (php-src-strict).
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
            new snmp2_get(),
            new snmp2_getnext(),
            new snmp2_walk(),
            new snmp2_real_walk(),
            new snmp2_set(),
            new snmp3_get(),
            new snmp3_getnext(),
            new snmp3_walk(),
            new snmp3_real_walk(),
            new snmp3_set(),
            new snmp_get_quick_print(),
            new snmp_set_quick_print(),
            new snmp_set_enum_print(),
            new snmp_set_oid_output_format(),
            new snmp_set_oid_numeric_print(),
            new snmp_set_valueretrieval(),
            new snmp_get_valueretrieval(),
            new snmp_read_mib(),
        ];
    }
}
