--TEST--
AOT: get_defined_constants(true) snmp bucket — no SNMP_* in user (#22858)
--FILE--
<?php
declare(strict_types=1);

$c = get_defined_constants(true);
echo isset($c['user']['SNMP_VERSION_1']) ? "snmp_in_user\n" : "snmp_not_user\n";

$loaded = extension_loaded('snmp');
if ($loaded) {
    echo isset($c['snmp']['SNMP_VERSION_1']) ? "snmp_bucket_ok\n" : "snmp_bucket_bad\n";
} else {
    echo !isset($c['snmp']) ? "snmp_bucket_ok\n" : "snmp_bucket_bad\n";
}
echo ($loaded === defined('SNMP_VERSION_1')) ? "snmp_defined_match\n" : "snmp_defined_mismatch\n";
--EXPECT--
snmp_not_user
snmp_bucket_ok
snmp_defined_match
