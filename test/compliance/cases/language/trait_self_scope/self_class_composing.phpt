--TEST--
self::class in trait methods returns composing class, not trait name (#18879, Zend/zend_traits.c)
--FILE--
<?php
trait T {
    public function f(): string {
        return self::class;
    }
    public static function g(): string {
        return self::class;
    }
    public static function staticClass(): string {
        return static::class;
    }
}
class C { use T; }
echo (new C)->f(), "\n";
echo C::g(), "\n";
echo T::g(), "\n";
echo C::staticClass(), "\n";
--EXPECT--
C
C
T
C
