--TEST--
Language: asymmetric visibility — explicit read before set modifier compile fatal (#7388, zend_compile.c)
--FILE--
<?php
class A {
    public private(set) string $x = 'a';
}
echo "compiled\n";
--EXPECT_EXIT--
255
