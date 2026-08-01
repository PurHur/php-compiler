--TEST--
Language: property default static::class rejected at compile time (#26629, zend_compile.c)
--FILE--
<?php
class A {
    public $x = static::class;
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: static::class cannot be used for compile-time class name resolution in %s on line %d
