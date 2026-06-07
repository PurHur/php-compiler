--TEST--
Language: `new` without `()` in class initializers must compile-error (#6549, Zend/zend_compile.c)
--FILE--
<?php
class ConstBad {
    const X = new stdClass;
}
--EXPECT_EXIT--
255
