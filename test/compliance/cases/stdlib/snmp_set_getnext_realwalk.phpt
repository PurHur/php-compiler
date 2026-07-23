--TEST--
stdlib snmpset/snmpgetnext/snmprealwalk + SNMP::set/getnext (#22244, php-src ext/snmp)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo function_exists('snmpset') ? '1' : '0';
echo function_exists('snmpgetnext') ? '1' : '0';
echo function_exists('snmprealwalk') ? '1' : '0';
echo "\n";

@$n = snmpgetnext('127.0.0.1', 'public', '1.3.6.1.2.1.1.1.0', 100000, 0);
echo false === $n ? 'next_false' : 'next_ok';
echo "\n";

@$rw = snmprealwalk('127.0.0.1', 'public', '1.3.6.1.2.1.1', 100000, 0);
echo false === $rw ? 'real_false' : 'real_ok';
echo "\n";

@$st = snmpset('127.0.0.1', 'public', '1.3.6.1.2.1.1.5.0', 's', 'host', 100000, 0);
echo false === $st ? 'set_false' : 'set_ok';
echo "\n";

$s = new SNMP(SNMP::VERSION_1, '127.0.0.1', 'public');
echo method_exists($s, 'set') ? '1' : '0';
echo method_exists($s, 'getnext') ? '1' : '0';
echo "\n";
@$g = $s->getnext('1.3.6.1.2.1.1.1.0');
echo false === $g ? 'obj_next_false' : 'obj_next_ok';
echo "\n";
@$o = $s->set('1.3.6.1.2.1.1.5.0', 's', 'host');
echo false === $o ? 'obj_set_false' : 'obj_set_ok';
echo "\n";
echo $s->close() ? 'closed' : 'close_fail';
echo "\n";
?>
--EXPECT--
111
next_false
real_false
set_false
11
obj_next_false
obj_set_false
closed
