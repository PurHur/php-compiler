--TEST--
Language: interface abstract static methods (PHP 8.4, #5090)
--FILE--
<?php
interface I {
    abstract public static function f(): void;
}
class C implements I {
    public static function f(): void { echo "impl\n"; }
}
C::f();
--EXPECT--
impl
