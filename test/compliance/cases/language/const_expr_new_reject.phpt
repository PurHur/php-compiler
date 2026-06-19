--TEST--
Language: bare `new` in class constant must compile-error before const fold (#10106, #6549, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public const X = new stdClass;
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: New expressions are not supported in this context
