--TEST--
Language: interface abstract static — static:: dispatch (#5090)
--FILE--
<?php
interface I {
    abstract public static function f(): void;
}
class C implements I {
    public static function f(): void { echo "impl\n"; }
    public static function via(): void { static::f(); }
}
C::via();
--EXPECT--
impl
