--TEST--
Static private Error names fetch class (self/static → child) — #29524, zend_object_handlers.c
--FILE--
<?php
class P {
    private static $x = 1;
    public static function get() { return self::$x; }
}
class C extends P {
    static function trySelf() {
        try { return self::$x; }
        catch (Throwable $e) { echo "self:", $e->getMessage(), "\n"; }
    }
    static function tryP() {
        try { return P::$x; }
        catch (Throwable $e) { echo "P:", $e->getMessage(), "\n"; }
    }
    static function tryParent() {
        try { return parent::$x; }
        catch (Throwable $e) { echo "parent:", $e->getMessage(), "\n"; }
    }
    static function tryStatic() {
        try { return static::$x; }
        catch (Throwable $e) { echo "static:", $e->getMessage(), "\n"; }
    }
}
echo "P=", P::get(), "\n";
C::trySelf();
C::tryP();
C::tryParent();
C::tryStatic();
--EXPECT--
P=1
self:Cannot access private property C::$x
P:Cannot access private property P::$x
parent:Cannot access private property P::$x
static:Cannot access private property C::$x
