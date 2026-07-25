<?php
declare(strict_types=1);

/**
 * Repro for #22858 — SNMP_* must not leak into get_defined_constants(true)['user'].
 * Reference profile: withheld. Forward profile (PHP_COMPILER_PROFILE=8.4): snmp bucket.
 */
$c = get_defined_constants(true);
$snmpInUser = isset($c['user']['SNMP_VERSION_1'])
    || isset($c['user']['SNMP_VALUE_PLAIN'])
    || isset($c['user']['SNMP_OID_OUTPUT_FULL']);

echo 'user_snmp=', $snmpInUser ? 'yes' : 'no', "\n";
echo 'snmp_bucket=', isset($c['snmp']) ? 'yes' : 'no', "\n";
echo 'SNMP_VERSION_1=', defined('SNMP_VERSION_1') ? 'def' : 'undef', "\n";
echo 'ext=', extension_loaded('snmp') ? 'yes' : 'no', "\n";
echo 'class=', class_exists('SNMP', false) ? 'yes' : 'no', "\n";

if (extension_loaded('snmp')) {
    echo 'snmp_VERSION_1=', isset($c['snmp']['SNMP_VERSION_1']) ? 'yes' : 'no', "\n";
    exit(!$snmpInUser && isset($c['snmp']['SNMP_VERSION_1']) ? 0 : 1);
}

exit(!$snmpInUser && !isset($c['snmp']) && !defined('SNMP_VERSION_1') ? 0 : 1);
