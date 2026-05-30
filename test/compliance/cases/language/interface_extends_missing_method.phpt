--TEST--
Language: interface extends — missing methods from parent interface (#3386)
--FILE--
<?php
interface I {
    public function f(): int;
}
interface J extends I {
    public function g(): void;
}
class C implements J {}
--EXPECT_EXIT--
255
