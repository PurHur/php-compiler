--TEST--
stdlib snmp phantom withhold on reference profile (#6070)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('snmpget') ? '1' : '0';
echo function_exists('snmpwalk') ? '1' : '0';
echo class_exists('SNMP', false) ? '1' : '0';
echo extension_loaded('snmp') ? '1' : '0';
echo "\n";
?>
--EXPECT--
0000
