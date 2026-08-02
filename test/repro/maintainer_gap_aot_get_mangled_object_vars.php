<?php
// #26797 — AOT get_mangled_object_vars must match Zend/VM (ext/standard/var.c)
class A {
    private $x = 1;
    protected $y = 2;
    public $z = 3;
}
$m = get_mangled_object_vars(new A());
echo 'count=', count($m), "\n";
foreach ($m as $k => $v) {
    echo 'hex=', bin2hex($k), ' val=', $v, "\n";
}
