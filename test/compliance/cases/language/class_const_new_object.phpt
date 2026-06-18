--TEST--
Language: class constants with object expressions rejected at compile (#9517, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public const X = new stdClass();
}
var_export(C::X);
--EXPECT_EXIT--
255
