--TEST--
self::/static:: non-static method from instance binds $this (issue #28050)
--FILE--
<?php
class A {
    protected function f(): string { return 'A'; }
}
class B extends A {
    protected function f(): string { return 'B'; }
    public function g(): string {
        return parent::f() . self::f() . static::f() . $this->f();
    }
}
echo (new B)->g(), "\n";

class C {
    public function f(): string { return 'C'; }
}
class D {
    public function g(): string {
        try {
            return C::f();
        } catch (Error $e) {
            return 'err';
        }
    }
}
echo (new D)->g(), "\n";
--EXPECT--
ABBB
err
