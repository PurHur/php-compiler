--TEST--
Language: duplicate method declarations — compile-time fatal (#5218, zend_compile.c)
--FILE--
<?php
class C {
    public function g() {}
    public function g() {}
}
echo "run\n";
--EXPECT_EXIT--
255
