--TEST--
Language: property hook block private(set); is compile-fatal (#29388, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public string $x {
        get => 'g';
        private(set);
    }
}
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Cannot use the private(set) modifier on a property hook in %s on line %d
