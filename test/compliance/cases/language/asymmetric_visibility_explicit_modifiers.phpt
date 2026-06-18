--TEST--
Language: asymmetric visibility — explicit read before set modifier compile fatal (#9161, zend_compile.c)
--FILE--
<?php
class A {
    public private(set) string $x = 'a';
}
echo "ok\n";
--EXPECT_EXIT--
255
