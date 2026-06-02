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
echo D::$n, " ", C::$n, "\n"; // per-class storage: D=2 C=0 (cloned on inherit)
