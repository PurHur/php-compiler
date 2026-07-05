--TEST--
Language: public private(set) unparenthesized — compile fatal (#16142, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=-: Multiple access type modifiers are not allowed
