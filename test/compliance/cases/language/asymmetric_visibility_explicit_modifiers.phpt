--TEST--
Language: asymmetric visibility — explicit read before set modifier compiles (#7460, zend_compile.c)
--FILE--
<?php
class A {
    public private(set) string $x = 'a';
}
echo (new A())->x, "\n";
--EXPECT--
a
