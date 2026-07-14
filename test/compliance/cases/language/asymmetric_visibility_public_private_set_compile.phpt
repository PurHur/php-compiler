--TEST--
Language: public private(set) unparenthesized — compile fatal (#18805, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
echo (new C())->x, "\n";
--EXPECT_EXIT--
255
