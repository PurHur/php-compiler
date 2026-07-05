--TEST--
Language: public protected(set) unparenthesized — parses and reads (#16161, Zend/zend_compile.c)
--FILE--
<?php
class A {
    public protected(set) string $x = 'ok';
}
echo (new A())->x, "\n";
--EXPECT--
ok
