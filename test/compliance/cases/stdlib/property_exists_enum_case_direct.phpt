--TEST--
Stdlib: property_exists() on enum case const — direct operand (#6108)
--FILE--
<?php
enum E: string
{
    case A = 'x';
}

var_export(property_exists(E::A, 'name'));
echo "\n";
var_export(property_exists(E::A, 'value'));
echo "\n";
var_export(property_exists(E::A, 'missing'));
echo "\n";
--EXPECT--
true
true
false
