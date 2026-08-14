--TEST--
SPL ArrayObject ARRAY_AS_PROPS property_exists on backing keys (#31039, ext/spl/spl_array.c)
--FILE--
<?php
$a = new ArrayObject(['x' => 1], ArrayObject::ARRAY_AS_PROPS);
$a->y = 2;
echo 'pe_x=', var_export(property_exists($a, 'x'), true), "\n";
echo 'pe_y=', var_export(property_exists($a, 'y'), true), "\n";
echo 'isset_y=', var_export(isset($a->y), true), "\n";
$b = new ArrayObject(['z' => null], ArrayObject::STD_PROP_LIST | ArrayObject::ARRAY_AS_PROPS);
echo 'pe_z=', var_export(property_exists($b, 'z'), true), "\n";
echo 'isset_z=', var_export(isset($b->z), true), "\n";
echo 'pe_missing=', var_export(property_exists($a, 'missing'), true), "\n";
?>
--EXPECT--
pe_x=true
pe_y=true
isset_y=true
pe_z=true
isset_z=false
pe_missing=false
