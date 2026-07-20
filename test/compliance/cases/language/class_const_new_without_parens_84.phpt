--TEST--
Language: class constant bare `new Class` rejected under PROFILE=8.4 (#21493, re-#18816, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Holder {
    public const OBJ = new stdClass;
}
echo get_class(Holder::OBJ), "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: New expressions are not supported in this context
