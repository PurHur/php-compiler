--TEST--
Language: parent:: in parentless class — compile-time fatal (issue #7381, zend_compile.c)
--FILE--
<?php
class C {
    public function f(): void {
        parent::g();
    }
}
--EXPECT_EXIT--
255
