--TEST--
Language: untyped set($value) on typed hooked property is compile-fatal (#29419)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public string $x = 'a' {
        set($value) { $this->x = $value . '!'; }
    }
}
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Type of parameter $value of hook C::$x::set must be compatible with property type in %s on line %d
