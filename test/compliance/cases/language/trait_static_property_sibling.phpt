--TEST--
Language: trait static properties — separate storage per using class (#4670)
--FILE--
<?php
trait Counter {
    public static $n = 0;
    public static function inc(): void {
        static::$n++;
    }
}
class A { use Counter; }
class B { use Counter; }

A::inc();
echo A::$n, " ", B::$n, "\n";
--EXPECT--
1 0
