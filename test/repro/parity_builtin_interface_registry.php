<?php
enum E: string { case A = 'a'; }
$c = E::A;

foreach (['UnitEnum', 'BackedEnum', 'ArrayAccess', 'Serializable'] as $iface) {
    echo "interface_exists($iface): ", var_export(interface_exists($iface), true), "\n";
}
echo "declared UnitEnum: ", var_export(in_array('UnitEnum', get_declared_interfaces(), true), true), "\n";
echo "is_a case UnitEnum: ";
try {
    var_export(is_a($c, UnitEnum::class));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage();
}
echo "\n";
