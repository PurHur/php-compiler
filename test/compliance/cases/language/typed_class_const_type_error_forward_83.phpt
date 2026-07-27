--TEST--
Language: typed class constant type mismatch under PHP_COMPILER_PROFILE=8.3 (#23757, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
class C {
    const string X = 123;
}
--EXPECT_EXIT--
255
