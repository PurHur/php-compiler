--TEST--
stdlib get_class_methods() — scope-visible private/protected (#23530, zend_builtin_functions.c)
--FILE--
<?php
class A {
    private function p() {}
    protected function r() {}
    public function u() {}
    public function t() { return get_class_methods($this); }
}
class B extends A {
    public function t2() { return get_class_methods($this); }
}
echo 'out=', implode(',', get_class_methods('A')), "\n";
echo 'in=', implode(',', (new A)->t()), "\n";
echo 'child=', implode(',', (new B)->t2()), "\n";
--EXPECT--
out=u,t
in=p,r,u,t
child=t2,r,u,t
