--TEST--
Language: class constant `new` expression rejected under JIT (#9974, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public const X = new stdClass();
}
var_export(C::X);
--EXPECT_EXIT--
255
