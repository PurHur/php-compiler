--TEST--
Language: public private(set) explicit read+set compile fatal (#9461, re-#9161, zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
echo "ok\n";
--EXPECT_EXIT--
255
