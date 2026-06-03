--TEST--
Language: static::$prop late static binding on inherited static properties (#4668)
--FILE--
<?php
class A {
    public static $p = 1;
    public static function read(): int {
        return static::$p;
    }
    public static function write(): void {
        static::$p = 9;
    }
    public static function append(int $x): void {
        static::$items[] = $x;
    }
    public static $items = [];
}
class B extends A {}

echo B::read(), "\n";
B::write();
echo B::$p, " ", A::$p, "\n";
B::append(1);
var_export(B::$items);
echo "\n";
class Base {
    protected static $p = 1;
}
class Child extends Base {
    public static function f(): int {
        return static::$p;
    }
}
echo Child::f(), "\n";
--EXPECT--
1
9 9
array (
  0 => 1,
)
1
