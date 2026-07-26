<?php
// Issue #23530 — get_class_methods() respects calling scope (zend_get_executed_scope).
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
