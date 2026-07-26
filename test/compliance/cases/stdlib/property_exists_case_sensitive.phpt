--TEST--
Stdlib: property_exists() property names are case-sensitive (#23532)
--FILE--
<?php
class A {
    public $FooBar = 1;
    private $Secret = 2;
    public static $StaticCase = 3;
    public function FooBar() { return 2; }
}
echo 'prop_exact=', (int) property_exists('A', 'FooBar'), "\n";
echo 'prop_lower=', (int) property_exists('A', 'foobar'), "\n";
echo 'priv_exact=', (int) property_exists('A', 'Secret'), "\n";
echo 'priv_lower=', (int) property_exists('A', 'secret'), "\n";
echo 'static_exact=', (int) property_exists('A', 'StaticCase'), "\n";
echo 'static_lower=', (int) property_exists('A', 'staticcase'), "\n";
echo 'meth_lower=', (int) method_exists('A', 'foobar'), "\n";
$o = new A;
echo 'isset_lower=', (int) isset($o->foobar), "\n";
echo 'obj_prop_lower=', (int) property_exists($o, 'foobar'), "\n";
--EXPECT--
prop_exact=1
prop_lower=0
priv_exact=1
priv_lower=0
static_exact=1
static_lower=0
meth_lower=1
isset_lower=0
obj_prop_lower=0
