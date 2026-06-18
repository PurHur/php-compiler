--TEST--
Language: public protected(set) compile fatal — explicit read before set modifier (#9654, PHP 8.4 zend_compile.c)
--FILE--
<?php
class A {
    public protected(set) string $x = 'ok';
}
echo "ok\n";
--EXPECT_EXIT--
255
