--TEST--
Stdlib: property_exists() — private parent properties invisible from child scope (VM, #4361)
--FILE--
<?php
class P {
    private $x;
    protected $z;
}
class C extends P {}
var_export(property_exists(C::class, 'x'));
echo "\n";
var_export(property_exists(new C(), 'x'));
echo "\n";
var_export(property_exists(new P(), 'x'));
echo "\n";
var_export(property_exists(C::class, 'z'));
echo "\n";
var_export(property_exists(new C(), 'z'));
echo "\n";
--EXPECT--
false
false
true
true
true
