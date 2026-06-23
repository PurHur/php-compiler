--TEST--
Language: untyped instance property `new` default compile-rejects (#10693, Zend/zend_compile.c)
--FILE--
<?php
class Box {
    public $inner = new stdClass();
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: New expressions are not supported in this context
