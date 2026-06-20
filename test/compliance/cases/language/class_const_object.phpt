--TEST--
Language: class constants with object expressions (#10198, Zend/zend_constants.c)
--FILE--
<?php
class C {
    public const X = new stdClass();
}
var_export(C::X);
--EXPECT--
(object) array (
)
