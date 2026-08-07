--TEST--
AOT: arrow fn reading $this binds object (issue #28612, Zend/zend_closures.c)
--FILE--
<?php
class A {
    private int $x = 5;
    function f() {
        $g = fn() => $this->x;
        return $g();
    }
}
echo (new A)->f(), PHP_EOL;

class B {
    private int $x = 7;
    function f() {
        $g = function () {
            return $this->x;
        };
        return $g();
    }
}
echo (new B)->f(), PHP_EOL;
--EXPECT--
5
7
