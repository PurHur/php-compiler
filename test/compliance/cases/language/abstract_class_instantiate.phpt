--TEST--
abstract class cannot be instantiated (issue #144)
--FILE--
<?php
abstract class A {
    public function f(): int { return 1; }
}
new A();
--EXPECT_EXIT--
255
