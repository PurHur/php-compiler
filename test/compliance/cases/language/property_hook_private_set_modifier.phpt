--TEST--
Language: private set(string) on property hook is compile-fatal (#29388)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public string $name {
        get => 'g';
        private set(string $v) {}
    }
}
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Cannot use the private modifier on a property hook in %s on line %d
