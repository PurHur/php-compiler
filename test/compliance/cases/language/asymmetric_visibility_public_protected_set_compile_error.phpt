--TEST--
Language: public protected(set) — compile fatal (#15184, Zend/zend_compile.c)
--FILE--
<?php
class A {
    public protected(set) string $x = 'ok';
}
echo (new A())->x, "\n";
--EXPECT_EXIT--
255
