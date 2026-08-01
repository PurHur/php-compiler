<?php
/**
 * #26659 — self::class / __CLASS__ in trait methods are the composing class.
 * Zend: self=C class=C static=C d_self=C d_static=D
 */
trait T {
    public static function viaSelf(): string { return self::class; }
    public static function viaClass(): string { return __CLASS__; }
    public static function viaStatic(): string { return static::class; }
}
class C { use T; }
class D extends C {}
echo 'self=', C::viaSelf(), "\n";
echo 'class=', C::viaClass(), "\n";
echo 'static=', C::viaStatic(), "\n";
echo 'd_self=', D::viaSelf(), "\n";
echo 'd_static=', D::viaStatic(), "\n";
