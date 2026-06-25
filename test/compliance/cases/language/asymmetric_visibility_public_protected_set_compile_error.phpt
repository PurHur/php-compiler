--TEST--
Language: public protected(set) compiles (#11546, PHP 8.4 zend_compile.c)
--FILE--
<?php
class A {
    public protected(set) string $x = 'ok';
}
echo (new A())->x, "\n";
--EXPECT--
ok
