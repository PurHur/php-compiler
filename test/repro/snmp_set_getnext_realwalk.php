<?php
/**
 * Issue #22244 — snmpset/snmpgetnext/snmprealwalk + SNMP::set/getnext.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/snmp_set_getnext_realwalk.php
 */
foreach (['snmpset', 'snmpgetnext', 'snmprealwalk'] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}
$s = new SNMP(SNMP::VERSION_1, '127.0.0.1', 'public');
echo 'set=', method_exists($s, 'set') ? 'Y' : 'N', "\n";
echo 'getnext=', method_exists($s, 'getnext') ? 'Y' : 'N', "\n";

@$r = snmpgetnext('127.0.0.1', 'public', '1.3.6.1.2.1.1.1.0', 100000, 0);
var_export($r);
echo "\n";
@$w = snmprealwalk('127.0.0.1', 'public', '1.3.6.1.2.1.1', 100000, 0);
var_export($w);
echo "\n";
@$set = snmpset('127.0.0.1', 'public', '1.3.6.1.2.1.1.5.0', 's', 'host', 100000, 0);
var_export($set);
echo "\n";
@$g = $s->getnext('1.3.6.1.2.1.1.1.0');
var_export($g);
echo "\n";
@$st = $s->set('1.3.6.1.2.1.1.5.0', 's', 'host');
var_export($st);
echo "\n";
$s->close();
echo "done\n";
