--TEST--
language: Closure::fromCallable([$this, private]) invokes (#27137, zend_closures.c)
--FILE--
<?php
class A {
    private function priv(): int {
        return 7;
    }
    public function run(): void {
        $c = Closure::fromCallable([$this, 'priv']);
        echo "this_form=", $c(), "\n";
    }
}
(new A())->run();
--EXPECT--
this_form=7
