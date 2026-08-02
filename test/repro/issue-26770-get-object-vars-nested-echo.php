<?php
class A {
    private $a = 1;
    protected $b = 2;
    public $c = 3;
}
class B extends A {
    private $d = 4;
    public function vars() { return get_object_vars($this); }
}
$b = new B();
echo 'B::vars keys='; echo implode(',', array_keys($b->vars())); echo "\n";
echo 'global keys='; echo implode(',', array_keys(get_object_vars($b))); echo "\n";
