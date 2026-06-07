--TEST--
Language: function call in class constant initializer must compile-error (#6843, Zend/zend_compile.c)
--FILE--
<?php
final class C {
    public const X = strlen('hi');
}
--EXPECT_EXIT--
255
