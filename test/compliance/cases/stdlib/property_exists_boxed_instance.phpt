--TEST--
Stdlib: property_exists() on boxed instance + stdClass dynamic props (VM, #32688)
--FILE--
<?php
class C {
    public $x = 1;
}
$c = new C;
var_dump(property_exists($c, 'x'));
$o = new stdClass();
$o->x = 1;
echo property_exists($o, 'x') ? '1' : '0';
echo property_exists($o, 'y') ? '1' : '0';
echo "\n";
--EXPECT--
bool(true)
10
