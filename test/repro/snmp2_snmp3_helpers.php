<?php
/**
 * Issue #22250 — snmp2 and snmp3 helpers + print/MIB/valueretrieval + SNMP::setSecurity.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/snmp2_snmp3_helpers.php
 */
foreach ([
    'snmp2_get', 'snmp2_set', 'snmp3_get', 'snmp3_set',
    'snmp_read_mib', 'snmp_set_valueretrieval', 'snmp_get_valueretrieval',
    'snmp2_getnext', 'snmp2_walk', 'snmp2_real_walk',
    'snmp3_getnext', 'snmp3_walk', 'snmp3_real_walk',
    'snmp_get_quick_print', 'snmp_set_quick_print', 'snmp_set_enum_print',
    'snmp_set_oid_output_format', 'snmp_set_oid_numeric_print',
] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', PHP_EOL;
}
echo 'setSecurity=', method_exists('SNMP', 'setSecurity') ? 'Y' : 'N', PHP_EOL;

snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
echo 'vr=', snmp_get_valueretrieval(), PHP_EOL;
snmp_set_quick_print(true);
echo 'qp=', snmp_get_quick_print() ? '1' : '0', PHP_EOL;

@$g = snmp2_get('127.0.0.1', 'public', '1.3.6.1.2.1.1.1.0', 100000, 0);
var_export($g);
echo PHP_EOL;
@$s3 = snmp3_get('127.0.0.1', 'user', 'authPriv', 'MD5', 'authpass', 'DES', 'privpass', '1.3.6.1.2.1.1.1.0', 100000, 0);
var_export($s3);
echo PHP_EOL;

$s = new SNMP(SNMP::VERSION_3, '127.0.0.1', 'public');
echo 'sec=', $s->setSecurity('authPriv', 'MD5', 'authpass', 'DES', 'privpass') ? '1' : '0', PHP_EOL;
$s->close();
echo "done\n";
