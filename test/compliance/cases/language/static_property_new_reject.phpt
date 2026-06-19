--TEST--
Language: static property `new` default compile-rejects (#10095, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public static DateTime $d = new DateTime('2020-01-01');
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: New expressions are not supported in this context
