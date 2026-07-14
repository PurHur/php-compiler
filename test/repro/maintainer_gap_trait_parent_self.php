<?php
/**
 * Issue #18879 — self::class in trait methods must return composing class.
 */
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

echo 'self-inst=', (new C)->f(), "\n";
echo 'self-stat=', C::g(), "\n";
echo 'trait-direct=', T::g(), "\n";
echo 'static-stat=', C::staticClass(), "\n";
