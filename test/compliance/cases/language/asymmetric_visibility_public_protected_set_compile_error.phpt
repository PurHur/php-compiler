--TEST--
Language: public protected(set) duplicate modifiers compile fatal (#9310, zend_compile.c)
--FILE--
<?php
class A {
    public protected(set) string $x = 'ok';
}
echo "ok\n";
--EXPECT_EXIT--
255
