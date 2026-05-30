--TEST--
Language: abstract class instantiation must fatal (issue #3385)
--FILE--
<?php
abstract class A {
    public function f(): int { return 1; }
}
new A();
--EXPECT_EXIT--
255
