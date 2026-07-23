--TEST--
stdlib snmp2/snmp3 + helpers + SNMP::setSecurity (#22250, php-src ext/snmp)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo function_exists('snmp2_get') ? '1' : '0';
echo function_exists('snmp2_set') ? '1' : '0';
echo function_exists('snmp3_get') ? '1' : '0';
echo function_exists('snmp3_set') ? '1' : '0';
echo function_exists('snmp_read_mib') ? '1' : '0';
echo function_exists('snmp_set_valueretrieval') ? '1' : '0';
echo function_exists('snmp_get_valueretrieval') ? '1' : '0';
echo method_exists('SNMP', 'setSecurity') ? '1' : '0';
echo "\n";

snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
echo snmp_get_valueretrieval() === SNMP_VALUE_PLAIN ? 'vr_ok' : 'vr_bad';
echo "\n";
snmp_set_quick_print(true);
echo snmp_get_quick_print() ? 'qp_ok' : 'qp_bad';
echo "\n";

@$g = snmp2_get('127.0.0.1', 'public', '1.3.6.1.2.1.1.1.0', 100000, 0);
echo false === $g ? 'v2_false' : 'v2_ok';
echo "\n";
@$s3 = snmp3_get('127.0.0.1', 'user', 'authPriv', 'MD5', 'a', 'DES', 'p', '1.3.6.1.2.1.1.1.0', 100000, 0);
echo false === $s3 ? 'v3_false' : 'v3_ok';
echo "\n";

$s = new SNMP(SNMP::VERSION_3, '127.0.0.1', 'public');
echo $s->setSecurity('authPriv', 'MD5', 'auth', 'DES', 'priv') ? 'sec_ok' : 'sec_bad';
echo "\n";
echo $s->close() ? 'closed' : 'close_fail';
echo "\n";
?>
--EXPECT--
11111111
vr_ok
qp_ok
v2_false
v3_false
sec_ok
closed
