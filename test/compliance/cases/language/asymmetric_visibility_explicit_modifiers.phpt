--TEST--
Language: asymmetric visibility — explicit read + set modifiers (#9724, PHP 8.4 zend_compile.c)
--FILE--
<?php
class A {
    private(set) string $x = 'a';
}
class B {
    private(set) protected int $y = 2;
}
$a = new A();
echo $a->x, "\n";
$b = new B();
try {
    echo $b->y, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
a
Error: Cannot access protected property B::$y
