<?php
class A {
    public $FooBar = 1;
    private $Secret = 2;
    public function FooBar() { return 2; }
}
echo 'prop_exact=', (int) property_exists('A', 'FooBar'), "\n";
echo 'prop_lower=', (int) property_exists('A', 'foobar'), "\n";
echo 'priv_exact=', (int) property_exists('A', 'Secret'), "\n";
echo 'priv_lower=', (int) property_exists('A', 'secret'), "\n";
echo 'meth_lower=', (int) method_exists('A', 'foobar'), "\n";
$o = new A;
echo 'isset_lower=', (int) isset($o->foobar), "\n";
