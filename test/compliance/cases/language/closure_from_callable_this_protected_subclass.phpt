--TEST--
language: Closure::fromCallable([$this, protected]) from subclass (#27143, zend_closures.c)
--FILE--
<?php
class A {
    protected function f(): string { return 'A'; }
}
class B extends A {
    public function g(): void {
        $c = Closure::fromCallable([$this, 'f']);
        echo "this_form=", $c(), "\n";
    }
}
(new B())->g();
--EXPECT--
this_form=A
