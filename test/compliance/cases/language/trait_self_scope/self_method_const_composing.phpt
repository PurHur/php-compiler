--TEST--
self:: in trait methods binds to composing class for methods, ::class, and constants (#19629, Zend/zend_traits.c)
--FILE--
<?php
trait T {
    public function who() { return self::name(); }
    public static function name() { return 'Tname'; }
    public function c() { return self::C; }
    public function cls() { return self::class; }
}
class A {
    use T;
    public static function name() { return 'Aname'; }
    const C = 'A';
}
class B extends A {
    public static function name() { return 'Bname'; }
    const C = 'B';
}
echo (new B)->who(), "\n";
echo (new B)->cls(), "\n";
echo (new B)->c(), "\n";
echo A::name() === 'Aname' ? "ok\n" : "bad\n";
--EXPECT--
Aname
A
A
ok
