--TEST--
AOT: instance method generators via $obj->g() (#35147, Zend/zend_generators.c)
--FILE--
<?php
class C {
    public function g() {
        yield 1;
        yield 2;
    }
}
foreach ((new C)->g() as $v) {
    echo $v;
}
echo "\n";
class A {
    public function g() {
        yield 3;
        yield 4;
    }
}
class B extends A {}
foreach ((new B)->g() as $v) {
    echo $v;
}
echo "\n";
--EXPECT--
12
34
