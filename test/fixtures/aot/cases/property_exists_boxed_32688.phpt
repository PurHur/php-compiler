--TEST--
AOT: property_exists() on a boxed instance and stdClass dynamic props (#32688)
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
--EXPECT_EXIT--
0
