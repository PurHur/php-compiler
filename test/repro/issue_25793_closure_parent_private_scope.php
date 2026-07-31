<?php
// Issue #25793 — closure from parent method reads declaring private $x (zend_object_handlers.c).
class A {
    private $x = "A";
    public function make() {
        return function () { return $this->x; };
    }
}
class B extends A {
    private $x = "B";
}
$fn = (new B)->make();
echo $fn(), "\n";
$rf = new ReflectionFunction($fn);
echo "scope=", $rf->getClosureScopeClass()?->getName(), "\n";
echo "this=", get_class($rf->getClosureThis()), "\n";
