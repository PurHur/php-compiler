<?php
// Repro #21983: self::who() must preserve late-static scope (Zend B-B, not A-B).
class A {
    public static function who() { return static::class; }
    public static function test() { return self::who() . "-" . static::who(); }
}
class B extends A {}
echo A::test(), "\n";
echo B::test(), "\n";
