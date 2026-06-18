--TEST--
Language: public private(set) compile fatal — explicit read before set modifier (#9654, PHP 8.4 zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
echo "ok\n";
--EXPECT_EXIT--
255
