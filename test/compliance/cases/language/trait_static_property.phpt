--TEST--
Language: trait static properties — per-class late static storage (#4670, Zend/zend_traits.c)
--FILE--
<?php
trait Counter {
    public static $n = 0;
    public static function inc(): void {
        static::$n++;
    }
}
class C { use Counter; }
class D extends C {}

D::inc();
D::inc();
echo D::$n, " ", C::$n, "\n";
--EXPECT--
2 0
