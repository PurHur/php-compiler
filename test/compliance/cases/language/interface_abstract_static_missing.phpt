--TEST--
Language: interface abstract static — missing implementation compile error (#5090)
--FILE--
<?php
interface I {
    abstract public static function f(): void;
}
class C implements I {}
new C;
--EXPECT_EXIT--
255
