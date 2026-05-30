--TEST--
Language: : static return type rejects unrelated object (issue #3412)
--FILE--
<?php
class B {
    public static function make(): static {
        return new static();
    }
}
class C extends B {
    public static function bad(): static {
        return new B();
    }
}
C::bad();
--EXPECT_EXIT--
255
