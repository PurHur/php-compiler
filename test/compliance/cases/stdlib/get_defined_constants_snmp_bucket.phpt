--TEST--
get_defined_constants(true) snmp bucket — no SNMP_* in user (#22858, re-#22337, zend_builtin_functions.c)
--FILE--
<?php
declare(strict_types=1);

$c = get_defined_constants(true);
$snmpInUser = 0;
if (isset($c['user'])) {
    foreach (array_keys($c['user']) as $name) {
        if (strncmp($name, 'SNMP_', 5) === 0) {
            $snmpInUser++;
        }
    }
}
echo $snmpInUser === 0 ? "snmp_not_user\n" : "snmp_in_user={$snmpInUser}\n";

$loaded = extension_loaded('snmp');
if ($loaded) {
    echo isset($c['snmp']['SNMP_VERSION_1']) ? "snmp_bucket_ok\n" : "snmp_bucket_bad\n";
} else {
    echo !isset($c['snmp']) ? "snmp_bucket_ok\n" : "snmp_bucket_bad\n";
}
echo ($loaded === defined('SNMP_VERSION_1')) ? "snmp_defined_match\n" : "snmp_defined_mismatch\n";
echo ($loaded === class_exists('SNMP', false)) ? "snmp_class_match\n" : "snmp_class_mismatch\n";

define('USER_CONST_22858', 1);
$c2 = get_defined_constants(true);
echo isset($c2['user']['USER_CONST_22858']) ? "define_user_ok\n" : "define_user_bad\n";
--EXPECT--
snmp_not_user
snmp_bucket_ok
snmp_defined_match
snmp_class_match
define_user_ok
