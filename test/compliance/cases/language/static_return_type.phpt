--TEST--
Language: : static return type accepts called-class instance (issue #3412)
--FILE--
<?php
class B {
    public static function make(): static {
        return new static();
    }
}
class C extends B {}
echo get_class(C::make());
--EXPECT--
C
