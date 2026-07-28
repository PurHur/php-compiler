--TEST--
Language: static property hooks rejected under PROFILE=8.4 (#24281, re-#9725, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public static int $x {
        get => 99;
    }
}
echo C::$x, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot declare hooks for static property in %s on line %d
