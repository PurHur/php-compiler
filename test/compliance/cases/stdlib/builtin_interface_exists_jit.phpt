--TEST--
Stdlib: Zend builtin interfaces — interface_exists/get_declared_interfaces/is_a (JIT, #6354)
--FILE--
<?php
enum E: string { case A = 'a'; }
$c = E::A;

foreach (['UnitEnum', 'BackedEnum', 'ArrayAccess', 'Serializable'] as $iface) {
    echo interface_exists($iface) ? '1' : '0';
}
echo "\n";
echo in_array('UnitEnum', get_declared_interfaces(), true) ? '1' : '0', "\n";
echo is_a($c, UnitEnum::class) ? '1' : '0', "\n";
--EXPECT--
1111
1
1
