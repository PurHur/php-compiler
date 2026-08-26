--TEST--
AOT: static method generators via Class::g() (#35153, Zend/zend_generators.c)
--FILE--
<?php
class C {
    public static function g() {
        yield 1;
        yield 2;
    }
}
foreach (C::g() as $v) {
    echo $v;
}
echo "\n";
class D {
    public static function g(): Generator {
        yield from [3, 4];
    }
}
foreach (D::g() as $v) {
    echo $v;
}
echo "\n";
class A {
    public static function g() {
        yield 5;
        yield 6;
    }
}
class B extends A {}
foreach (B::g() as $v) {
    echo $v;
}
echo "\n";
--EXPECT--
12
34
56
