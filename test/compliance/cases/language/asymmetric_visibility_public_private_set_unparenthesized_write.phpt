--TEST--
Language: public private(set) unparenthesized — compile fatal (#18805, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class B {
    public private(set) string $label = 'hi';
}
$b = new B();
echo $b->label, "\n";
--EXPECT_EXIT--
255
