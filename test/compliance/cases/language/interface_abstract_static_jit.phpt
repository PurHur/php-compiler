--TEST--
Language: interface abstract static methods — JIT VM fallback until MCJIT stable (#5090)
--JIT--
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
