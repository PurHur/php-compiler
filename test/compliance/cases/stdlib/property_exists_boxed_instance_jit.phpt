--TEST--
Stdlib: property_exists() on boxed instance + stdClass dynamic props (JIT, #32688)
--FILE--
<?php
class C {
    public $x = 1;
}
$c = new C;
var_dump(property_exists($c, 'x'));
$o = new stdClass();
$o->dyn = 1;
echo property_exists($o, 'dyn') ? '1' : '0';
echo property_exists($o, 'missing') ? '1' : '0';
echo "\n";
--EXPECT--
bool(true)
10
