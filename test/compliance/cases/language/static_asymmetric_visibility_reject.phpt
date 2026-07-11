--TEST--
PHP 8.4 static asymmetric visibility: explicit read + set modifier compile fatal (#7013, zend_compile.c)
--FILE--
<?php
class C {
    public (private(set)) static int $x = 1;
}
echo C::$x, "\n";
--EXPECT_EXIT--
255
