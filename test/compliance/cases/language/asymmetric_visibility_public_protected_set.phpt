--TEST--
Language: public protected(set) unparenthesized — compile fatal (#18805, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A {
    public protected(set) string $x = 'ok';
}
echo (new A())->x, "\n";
--EXPECT_EXIT--
255
