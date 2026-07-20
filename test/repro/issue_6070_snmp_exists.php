<?php
/**
 * Issue #6070 — ext/snmp snmpget/snmpwalk + SNMP class registration.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_6070_snmp_exists.php
 */
echo function_exists('snmpget') ? 'yes' : 'no';
echo "\n";
echo function_exists('snmpwalk') ? 'yes' : 'no';
echo "\n";
echo class_exists('SNMP', false) ? 'yes' : 'no';
echo "\n";
echo extension_loaded('snmp') ? 'yes' : 'no';
echo "\n";

@$r = snmpget('127.0.0.1', 'public', '1.3.6.1.2.1.1.1.0', 100000, 0);
var_export($r);
echo "\n";

$s = new SNMP(SNMP::VERSION_1, '127.0.0.1', 'public');
@$g = $s->get('1.3.6.1.2.1.1.1.0');
var_export($g);
echo "\n";
echo $s->getErrno() > 0 ? 'err' : 'ok';
echo "\n";
$s->close();
echo "done\n";
