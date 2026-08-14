<?php
// Issue #31039 — property_exists() on ArrayObject ARRAY_AS_PROPS keys.
$a = new ArrayObject(['x' => 1], ArrayObject::ARRAY_AS_PROPS);
$a->y = 2;
echo 'pe_x=', var_export(property_exists($a, 'x'), true), "\n";
echo 'pe_y=', var_export(property_exists($a, 'y'), true), "\n";
echo 'isset_y=', var_export(isset($a->y), true), "\n";

$a2 = new ArrayObject(['a' => 1], ArrayObject::STD_PROP_LIST | ArrayObject::ARRAY_AS_PROPS);
$a2->b = 2;
echo 'pe_b=', var_export(property_exists($a2, 'b'), true), "\n";
