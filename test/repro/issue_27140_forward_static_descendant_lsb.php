<?php
/**
 * #27140 — forward_static_call([Descendant::class, method]) uses descendant called_scope.
 * Zend: A::viaB() → B; ancestor forward keeps caller LSB.
 */
class A {
    public static function who() {
        return static::class;
    }
    public static function viaB() {
        return forward_static_call([B::class, 'who']);
    }
}
class B extends A {
    public static function viaB() {
        return forward_static_call([B::class, 'who']);
    }
    public static function viaParent() {
        return forward_static_call('parent::who');
    }
}
class C extends B {
    public static function viaA() {
        return forward_static_call([A::class, 'who']);
    }
}
echo A::viaB(), "\n";
echo B::viaB(), "\n";
echo C::viaA(), "\n";
echo B::viaParent(), "\n";
