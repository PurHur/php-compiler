--TEST--
Language: static property hooks must compile-error (issue #6619, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public static int $x {
        get => 1;
    }
}
echo C::$x, "\n";
--EXPECT_EXIT--
255
