--TEST--
Language: asymmetric visibility — public+private(set)/public+protected(set) compile fatal (#7099, zend_compile.c)
--FILE--
<?php
class A {
    public private(set) string $x = 'a';
}
echo "compiled\n";
--EXPECT_EXIT--
255
--FILE--
<?php
class B {
    public protected(set) string $x = 'a';
}
echo "compiled\n";
--EXPECT_EXIT--
255
