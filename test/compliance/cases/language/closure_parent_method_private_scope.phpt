--TEST--
language: parent-method closure reads declaring private, not child shadow (#25793, zend_object_handlers.c)
--FILE--
<?php
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
--EXPECT--
A
scope=A
this=B
