--TEST--
Language: class constants with object expressions rejected (#10391, Zend/zend_constants.c)
--FILE--
<?php
class C {
    public const X = new stdClass();
}
var_export(C::X);
--EXPECT_EXIT--
255
