--TEST--
Language: asymmetric visibility property `new` default rejected under PROFILE=8.4 (#21869, re-#21493, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class S {
    public private(set) stdClass $obj = new stdClass();
}
?>
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: New expressions are not supported in this context
