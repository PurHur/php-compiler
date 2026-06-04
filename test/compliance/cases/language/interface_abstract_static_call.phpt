--TEST--
Language: interface abstract static call must throw Cannot call abstract method (#5383)
--FILE--
<?php
interface I {
    public static function f(): void;
}
I::f();
--EXPECT_EXIT--
255
