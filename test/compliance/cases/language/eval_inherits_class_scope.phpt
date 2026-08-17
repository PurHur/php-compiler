--TEST--
language eval() inherits self/static class scope from instance and static methods (#31912)
--FILE--
<?php
class C {
    public const K = 7;
    public static $s = 3;
    public function f() {
        echo eval('return self::class;'), "\n";
        echo eval('return static::class;'), "\n";
        echo eval('return self::K;'), "\n";
        echo eval('return self::$s;'), "\n";
    }
    public static function g() {
        echo eval('return self::class;'), "\n";
    }
}
class D extends C {}
(new D())->f();
C::g();
--EXPECT--
C
D
7
3
C
