--TEST--
Language: public protected(set) — parses and reads publicly (#13672, Zend/zend_compile.c)
--FILE--
<?php
class A {
    public protected(set) string $x = 'ok';
}
echo (new A())->x, "\n";
--EXPECT--
ok
