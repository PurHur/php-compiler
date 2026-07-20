--TEST--
stdlib snmpget/snmpwalk + SNMP class_exists (#6070, php-src ext/snmp)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo function_exists('snmpget') ? '1' : '0';
echo function_exists('snmpwalk') ? '1' : '0';
echo class_exists('SNMP', false) ? '1' : '0';
echo extension_loaded('snmp') ? '1' : '0';
echo "\n";

@$r = snmpget('127.0.0.1', 'public', '1.3.6.1.2.1.1.1.0', 100000, 0);
echo false === $r ? 'get_false' : 'get_ok';
echo "\n";

@$w = snmpwalk('127.0.0.1', 'public', '1.3.6.1.2.1.1', 100000, 0);
echo false === $w ? 'walk_false' : 'walk_ok';
echo "\n";

$s = new SNMP(SNMP::VERSION_1, '127.0.0.1', 'public');
@$g = $s->get('1.3.6.1.2.1.1.1.0');
echo false === $g ? 'obj_false' : 'obj_ok';
echo "\n";
echo $s->close() ? 'closed' : 'close_fail';
echo "\n";
?>
--EXPECT--
1111
get_false
walk_false
obj_false
closed
