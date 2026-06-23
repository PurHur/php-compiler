--TEST--
Language: instance typed property `new` default compile-rejects (#10693, Zend/zend_compile.c)
--FILE--
<?php
class Logger {}
class C {
    public Logger $l = new Logger();
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: New expressions are not supported in this context
