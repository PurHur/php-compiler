--TEST--
Language: parent:: in enum — compile-time fatal (issue #5410, zend_compile.c)
--FILE--
<?php
enum E {
    case A;
    public function f() {
        parent::x;
    }
}
--EXPECT_EXIT--
255
